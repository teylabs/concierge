<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Exceptions\ImportRefused;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

/**
 * One-shot copy of an existing team or organisation pivot into concierge's own
 * memberships and ownership. It reads the pivot and never changes it, and a
 * person who already has a concierge membership in that workspace, in any
 * status, is left alone, so a second run has nothing to do.
 *
 * The owner of a workspace without one is, in this order: the earliest member
 * whose role column holds the owner role, the user in the model's owner column,
 * or the earliest member.
 */
class ImportMembersCommand extends Command
{
    public $signature = 'concierge:import-members
        {--model= : The team or organisation model; defaults to concierge.models.workspace and must match it}
        {--pivot= : The membership pivot table; defaults to the Laravel convention, e.g. organisation_user}
        {--foreign-key= : The pivot column holding the model\'s key; defaults to <model>_id}
        {--user-key=user_id : The pivot column holding the user\'s key}
        {--role-column= : The pivot column holding each member\'s role}
        {--owner-role= : The role-column value that marks the owner; defaults to concierge.owner_role}
        {--owner-column=owner_id : A column on the model\'s table holding the owner\'s user id, used when no member has the owner role}
        {--map=* : Map a role-column value to a concierge role, as from:to, e.g. --map=member:presenter}
        {--default-role= : The concierge role for members whose role does not map, or for every member without --role-column}
        {--dry-run : Show what would be imported, and write nothing}
        {--json : Print the plan or the result as JSON}';

    public $description = 'Copy an existing team or organisation pivot\'s members, and an owner, into concierge. Safe to run again.';

    public function handle(Concierge $concierge): int
    {
        try {
            $options = $this->preflight();
            $plan = $this->plan($options);
            $dryRun = (bool) $this->option('dry-run');

            if (! $dryRun && ! $plan['nothing_to_do']) {
                $this->import($concierge, $plan);
            }
        } catch (ImportRefused $refused) {
            return $this->refuse($refused);
        } catch (UnsupportedConfiguration $exception) {
            return $this->refuse(new ImportRefused($exception->getMessage(), 'Correct the configuration named above, then rerun php artisan concierge:import-members.'));
        } catch (QueryException $exception) {
            return $this->refuse(new ImportRefused($exception->getMessage(), 'Check database.default in config/database.php and the source pivot options, and run php artisan migrate before retrying php artisan concierge:import-members.'));
        }

        $result = ['dry_run' => $dryRun] + $plan;

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    /**
     * @return array{model: class-string<Model&Workspace>, pivot: string, foreign_key: string, user_key: string, role_column: ?string, owner_role: string, owner_column: ?string, map: array<string, string>, default_role: ?string, order: list<string>}
     */
    protected function preflight(): array
    {
        if (Mode::configured() !== Mode::Workspaces) {
            throw new ImportRefused(
                'Concierge is in app-wide mode, which has no workspaces to import members into.',
                "Set 'mode' => 'workspaces' in config/concierge.php.",
            );
        }

        if (WorkspaceEngine::configured() === WorkspaceEngine::Kit) {
            throw new ImportRefused(
                "concierge.workspaces.engine is 'kit': concierge already reads the starter kit's team members, so there is nothing to import.",
                "To keep a non-kit model's members in concierge's own tables instead, set 'workspaces' => ['engine' => 'concierge'] in config/concierge.php.",
            );
        }

        $configured = config('concierge.models.workspace');
        $model = $this->option('model') ?: $configured;

        if (! is_string($model) || ! class_exists($model)) {
            throw new ImportRefused(
                'No workspace model to import into'.(is_string($model) ? ": [{$model}] does not exist." : '.'),
                'Pass --model=App\\Models\\Organisation and set concierge.models.workspace to the same class.',
            );
        }

        $model = ltrim($model, '\\');

        if ($model !== ltrim((string) $configured, '\\')) {
            throw new ImportRefused(
                "[{$model}] is not concierge.models.workspace".($configured ? " ([{$configured}])" : '').'; memberships can only point at the configured workspace model.',
                "Set 'models' => ['workspace' => {$model}::class] in config/concierge.php, migrate, then run this again.",
            );
        }

        try {
            $model = ConciergeModels::workspace();
        } catch (LogicException $exception) {
            throw new ImportRefused($exception->getMessage(), "Add `implements Tey\\Concierge\\Contracts\\Workspace` and `use Tey\\Concierge\\Concerns\\ActsAsWorkspace, Tey\\Concierge\\Concerns\\HasMembers;` to [{$model}].");
        }

        if (! config('permission.teams')) {
            throw new ImportRefused(
                "Concierge's own workspace engine needs Spatie teams, and permission.teams is false.",
                "Set 'teams' => true and 'column_names' => ['team_foreign_key' => 'workspace_id'] in config/permission.php, then migrate.",
            );
        }

        $instance = new $model;
        $basename = Str::snake(class_basename($model));
        $pivot = $this->option('pivot') ?: implode('_', collect([$basename, 'user'])->sort()->all());
        $foreignKey = $this->option('foreign-key') ?: "{$basename}_id";
        $userKey = (string) $this->option('user-key');
        $roleColumn = $this->option('role-column') ?: null;

        if (! Schema::hasTable($pivot)) {
            throw new ImportRefused("The pivot table [{$pivot}] does not exist.", 'Pass --pivot=<table>, the table that joins users to '.class_basename($model).'.');
        }

        foreach (array_filter(['--foreign-key' => $foreignKey, '--user-key' => $userKey, '--role-column' => $roleColumn]) as $option => $column) {
            if (! Schema::hasColumn($pivot, $column)) {
                throw new ImportRefused("The pivot table [{$pivot}] has no [{$column}] column.", "Pass {$option}=<column>; the table has: ".implode(', ', Schema::getColumnListing($pivot)).'.');
            }
        }

        $ownerColumn = (string) $this->option('owner-column');
        $ownerColumn = $ownerColumn !== '' && Schema::hasColumn($instance->getTable(), $ownerColumn) ? $ownerColumn : null;

        $map = [];

        foreach ((array) $this->option('map') as $pair) {
            [$from, $to] = array_pad(explode(':', (string) $pair, 2), 2, '');

            if ($from === '' || $to === '') {
                throw new ImportRefused("--map={$pair} is not from:to.", 'Write it as --map=<role-column value>:<concierge role>, e.g. --map=member:presenter.');
            }

            $map[$from] = $to;
        }

        $defaultRole = $this->option('default-role') ?: null;
        $owner = Roles::owner();

        foreach ([...array_values($map), ...array_filter([$defaultRole])] as $role) {
            if (Roles::rank($role) === null) {
                throw new ImportRefused("[{$role}] is not a concierge role.", 'Use one of the roles in config/concierge.php: '.implode(', ', Roles::configured()).'.');
            }

            if ($role === $owner) {
                throw new ImportRefused("[{$owner}] cannot be mapped to: a workspace has exactly one owner, chosen by the import.", 'Map to a lower role, e.g. '.config('concierge.transfer_demotes_to', 'admin').'.');
            }
        }

        $order = array_values(array_filter(['created_at', 'id'], fn ($column) => Schema::hasColumn($pivot, $column)));

        return [
            'model' => $model,
            'pivot' => $pivot,
            'foreign_key' => $foreignKey,
            'user_key' => $userKey,
            'role_column' => $roleColumn,
            'owner_role' => $this->option('owner-role') ?: $owner,
            'owner_column' => $ownerColumn,
            'map' => $map,
            'default_role' => $defaultRole,
            'order' => $order,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function plan(array $options): array
    {
        /** @var class-string<Model&Workspace> $model */
        $model = $options['model'];
        $workspaces = [];
        $unmapped = [];
        $totals = ['workspaces' => 0, 'owners' => 0, 'members' => 0, 'skipped' => 0];

        $users = ConciergeModels::user();
        $userKey = (new $users)->getKeyName();

        foreach ($model::query()->orderBy((new $model)->getKeyName())->cursor() as $workspace) {
            $rows = DB::table($options['pivot'])
                ->where($options['foreign_key'], $workspace->getKey())
                ->when($options['order'] !== [], fn ($query) => array_reduce($options['order'], fn ($query, $column) => $query->orderBy($column), $query))
                ->orderBy($options['user_key'])
                ->get();

            $members = [];

            foreach ($rows as $row) {
                $members[(int) $row->{$options['user_key']}] ??= [
                    'role' => $options['role_column'] ? (string) $row->{$options['role_column']} : null,
                    'joined_at' => $row->created_at ?? null,
                ];
            }

            [$ownerId, $ownerSource] = $this->chooseOwner($workspace, $members, $options);

            if ($members === [] && $ownerId === null) {
                continue;
            }

            $existing = ConciergeModels::membership()::query()
                ->where('workspace_id', $workspace->getKey())
                ->pluck('status', 'user_id')
                ->mapWithKeys(fn ($status, $id) => [(int) $id => $status instanceof MembershipStatus ? $status->value : (string) $status])
                ->all();

            $currentOwner = app(Concierge::class)->ownerOf($workspace);
            $emails = $users::query()->whereKey(array_unique([...array_keys($members), ...array_filter([$ownerId])]))->pluck('email', $userKey)->all();

            $owner = $currentOwner
                ? ['user_id' => (int) $currentOwner->user_id, 'email' => $emails[(int) $currentOwner->user_id] ?? null, 'source' => 'existing', 'action' => 'keep']
                : ['user_id' => $ownerId, 'email' => $emails[$ownerId] ?? null, 'source' => $ownerSource, 'action' => 'establish'];

            $entries = [];

            foreach ($members as $userId => $member) {
                if ($owner['action'] === 'establish' && $userId === $ownerId) {
                    continue;
                }

                $entry = ['user_id' => $userId, 'email' => $emails[$userId] ?? null, 'source_role' => $member['role']];

                if (isset($existing[$userId])) {
                    $entries[] = $entry + ['role' => null, 'action' => 'skip', 'reason' => "already a member ({$existing[$userId]})"];
                    $totals['skipped']++;

                    continue;
                }

                $role = $this->mapRole($member['role'], $options);

                if ($role === null) {
                    $unmapped[$member['role'] ?? ''] = true;
                }

                $entries[] = $entry + ['role' => $role, 'action' => 'import', 'joined_at' => $member['joined_at']];
                $totals['members']++;
            }

            if ($owner['action'] === 'establish' && isset($existing[$ownerId])) {
                $owner['note'] = "already a member ({$existing[$ownerId]}); the import makes them the active owner";
            }

            $totals['workspaces']++;
            $totals['owners'] += $owner['action'] === 'establish' ? 1 : 0;

            $workspaces[] = [
                'id' => (int) $workspace->getKey(),
                'name' => $workspace->conciergeName(),
                'owner' => $owner,
                'members' => $entries,
            ];
        }

        if ($unmapped !== []) {
            $values = implode(', ', array_map(fn ($value) => $value === '' ? '(no role)' : "[{$value}]", array_keys($unmapped)));

            throw new ImportRefused(
                "No concierge role for the role-column value(s) {$values}.",
                'Pass --map=<value>:<concierge role> for each, or --default-role=<concierge role>; the roles are: '.implode(', ', array_diff(Roles::configured(), [Roles::owner()])).'.',
            );
        }

        return [
            'model' => $model,
            'pivot' => $options['pivot'],
            'workspaces' => $workspaces,
            'summary' => $totals,
            'nothing_to_do' => $totals['owners'] === 0 && $totals['members'] === 0,
        ];
    }

    /**
     * @param  array<int, array{role: ?string, joined_at: mixed}>  $members
     * @param  array<string, mixed>  $options
     * @return array{0: ?int, 1: ?string}
     */
    protected function chooseOwner(Model&Workspace $workspace, array $members, array $options): array
    {
        if ($options['role_column']) {
            foreach ($members as $userId => $member) {
                if ($member['role'] === $options['owner_role']) {
                    return [$userId, 'role'];
                }
            }
        }

        if ($options['owner_column'] && ($id = $workspace->getAttribute($options['owner_column'])) !== null) {
            if (ConciergeModels::user()::query()->whereKey($id)->exists()) {
                return [(int) $id, 'owner_column'];
            }
        }

        $first = array_key_first($members);

        return $first === null ? [null, null] : [$first, 'earliest'];
    }

    /** @param  array<string, mixed>  $options */
    protected function mapRole(?string $value, array $options): ?string
    {
        if ($value !== null && isset($options['map'][$value])) {
            return $options['map'][$value];
        }

        // Concierge has one owner per workspace; any other owner keeps the rank below it.
        if ($value !== null && $value === $options['owner_role']) {
            return config('concierge.transfer_demotes_to', 'admin');
        }

        if ($value !== null && $value !== Roles::owner() && Roles::rank($value) !== null) {
            return $value;
        }

        return $options['default_role'];
    }

    /** @param  array<string, mixed>  $plan */
    protected function import(Concierge $concierge, array $plan): void
    {
        SyncRoleDefinitions::run();

        /** @var class-string<Model&Workspace> $model */
        $model = $plan['model'];
        $users = ConciergeModels::user();

        foreach ($plan['workspaces'] as $entry) {
            DB::transaction(function () use ($concierge, $users, $entry) {
                $workspace = Locks::workspace($entry['id']);

                if ($entry['owner']['action'] === 'establish') {
                    EstablishWorkspace::run($workspace, $users::query()->findOrFail($entry['owner']['user_id']));
                }

                foreach ($entry['members'] as $member) {
                    if ($member['action'] !== 'import') {
                        continue;
                    }

                    $user = $users::query()->findOrFail($member['user_id']);

                    $exists = ConciergeModels::membership()::query()
                        ->where('workspace_id', $workspace->getKey())
                        ->where('user_id', $user->getKey())
                        ->lockForUpdate()
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    ConciergeModels::membership()::query()->create([
                        'workspace_id' => $workspace->getKey(),
                        'user_id' => $user->getKey(),
                        'status' => MembershipStatus::Active,
                        'joined_at' => $member['joined_at'] ?? now(),
                    ]);

                    $concierge->context()->run(
                        $workspace,
                        fn () => $user->syncRoles(Roles::resolve([$member['role']])),
                        [$user],
                    );
                }
            });
        }
    }

    /** @param  array<string, mixed>  $result */
    protected function report(array $result): void
    {
        $summary = $result['summary'];

        if ($result['nothing_to_do']) {
            $this->components->info("Nothing to import from [{$result['pivot']}]: every member is already in concierge ({$summary['skipped']} skipped).");

            return;
        }

        $verb = $result['dry_run'] ? 'Would import' : 'Imported';

        foreach ($result['workspaces'] as $workspace) {
            $owner = $workspace['owner'];
            $this->components->twoColumnDetail(
                "<fg=cyan>{$workspace['name']}</> [{$workspace['id']}]",
                $owner['action'] === 'establish'
                    ? "owner {$owner['email']} (from {$owner['source']})"
                    : "owner {$owner['email']} (kept)",
            );

            foreach ($workspace['members'] as $member) {
                $this->components->twoColumnDetail(
                    "  {$member['email']}",
                    $member['action'] === 'import' ? $member['role'] : "<fg=gray>skip: {$member['reason']}</>",
                );
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s %d member(s) and %d owner(s) into %d workspace(s); %d skipped.%s',
            $verb,
            $summary['members'],
            $summary['owners'],
            $summary['workspaces'],
            $summary['skipped'],
            $result['dry_run'] ? ' Run again without --dry-run to write it.' : " [{$result['pivot']}] is unchanged; concierge no longer reads it.",
        ));
    }

    protected function refuse(ImportRefused $refused): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $refused->getMessage(), 'fix' => $refused->fix], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($refused->getMessage());
            $this->line("  Fix: {$refused->fix}");
        }

        return self::FAILURE;
    }
}
