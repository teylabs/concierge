<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Install\AppInspector;
use Tey\Concierge\Install\InstallRefused;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;
use Throwable;

/**
 * Sets concierge up in three passes: detect what it can, ask only what it
 * can't, then run each step once. Every step reports done, already, would
 * (dry run), manual (the developer must do it) or blocked (an earlier manual
 * step stops it). Running it again changes nothing that is already set up.
 */
class InstallCommand extends Command
{
    public $signature = 'concierge:install
        {--mode= : app or workspaces (default app)}
        {--workspace-model= : The model to use as workspaces in workspace mode}
        {--local-login : Turn on local login when the app has no sign-in}
        {--no-migrate : Write config but don\'t run migrations or sync roles}
        {--dry-run : Show the plan and change nothing}
        {--json : Print what was detected and each step as JSON}';

    public $description = 'Detect the app, ask only what can\'t be detected, and set concierge up. Safe to run again.';

    /** @var list<array{step: string, status: string, detail: string}> */
    protected array $steps = [];

    protected bool $blocked = false;

    public function handle(AppInspector $inspector): int
    {
        $detected = $inspector->inspect();

        try {
            $choices = $this->choices($detected);
        } catch (InstallRefused $refused) {
            return $this->refuse($refused->getMessage(), $detected);
        }

        $this->permissionConfig($detected, $choices);
        $this->permissionMigrations($detected);
        $this->conciergeConfig($detected, $choices);
        $this->contract('user-model', $choices['user_model'], Member::class, "use Spatie\\Permission\\Traits\\HasRoles;\nuse Tey\\Concierge\\Concerns\\InteractsWithMemberships;\nuse Tey\\Concierge\\Contracts\\Member;\n\nclass User extends Authenticatable implements Member\n{\n    use HasRoles, InteractsWithMemberships;");
        if ($choices['mode'] === 'workspaces') {
            $this->contract('workspace-model', $choices['workspace_model'], Workspace::class, "use Tey\\Concierge\\Concerns\\ActsAsWorkspace;\nuse Tey\\Concierge\\Contracts\\Workspace;\n\nclass ".class_basename((string) $choices['workspace_model'])." extends Model implements Workspace\n{\n    use ActsAsWorkspace;");
        }
        $this->migrateAndSyncRoles();
        $this->firstOwner($choices);
        $this->frontend($detected);

        $result = [
            'detected' => $detected,
            'choices' => $choices,
            'steps' => $this->steps,
            'set_up' => ! $this->blocked && ! $this->option('dry-run') && file_exists(config_path('concierge.php')),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->report($result);
        }

        return $this->blocked ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $detected
     * @return array{mode: string, engine: string, user_model: ?string, workspace_model: ?string, local_login: bool}
     */
    protected function choices(array $detected): array
    {
        // An app that is already set up keeps what its config says.
        if ($detected['concierge']['config_published']) {
            $mode = config('concierge.mode') ?? (config('concierge.models.workspace') ? 'workspaces' : 'app');

            return [
                'mode' => $mode,
                'engine' => $mode === 'workspaces' ? (config('concierge.workspaces.engine') ?? 'concierge') : 'concierge',
                'user_model' => config('concierge.models.user') ?? $detected['user_model'],
                'workspace_model' => config('concierge.models.workspace'),
                'local_login' => (bool) config('concierge.local_login.enabled'),
            ];
        }

        $model = $this->option('workspace-model');
        $mode = $this->option('mode') ?? ($model ? 'workspaces' : null);

        if ($mode === null && $detected['team_model'] && $this->input->isInteractive()) {
            $mode = $this->confirm("Use {$detected['team_model']} as concierge's workspaces?", false) ? 'workspaces' : 'app';
            $model = $mode === 'workspaces' ? $detected['team_model'] : null;
        }

        $mode ??= $this->input->isInteractive()
            ? $this->choice('Where do invitations and roles apply?', ['app' => 'The whole app', 'workspaces' => 'One workspace at a time'], 'app')
            : 'app';

        if (! in_array($mode, ['app', 'workspaces'], true)) {
            throw new InstallRefused("--mode must be app or workspaces; got [{$mode}].");
        }

        if ($mode === 'workspaces') {
            $model ??= $detected['team_model'];

            if (! is_string($model) || ! class_exists($model)) {
                throw new InstallRefused('Workspace mode needs a workspace model; pass --workspace-model=App\\Models\\Team (or your own model).');
            }
        }

        $localLogin = (bool) $this->option('local-login');

        if (! $localLogin && $detected['sign_in'] === null && $this->input->isInteractive()) {
            $localLogin = $this->confirm('No sign-in was found. Turn on local login for development?', false);
        }

        return [
            'mode' => $mode,
            'engine' => $mode === 'workspaces' && $detected['kit_teams'] && class_basename((string) $model) === 'Team' ? 'kit' : 'concierge',
            'user_model' => $detected['user_model'],
            'workspace_model' => $mode === 'workspaces' ? $model : null,
            'local_login' => $localLogin,
        ];
    }

    /**
     * Spatie teams on only for workspace mode on concierge's own engine; app
     * mode and a starter kit's teams keep it off.
     *
     * @param  array<string, mixed>  $detected
     * @param  array<string, mixed>  $choices
     */
    protected function permissionConfig(array $detected, array $choices): void
    {
        $teams = $choices['mode'] === 'workspaces' && $choices['engine'] === 'concierge';

        if ($detected['permission']['config_published']) {
            $this->step('permission-config', 'already', 'config/permission.php');
        } elseif ($this->option('dry-run')) {
            $this->step('permission-config', 'would', 'publish config/permission.php');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => 'permission-config']);
            $this->step('permission-config', 'done', 'published config/permission.php');
        }

        if ((bool) config('permission.teams') === $teams && $detected['permission']['config_published']) {
            $this->step('permission-teams', 'already', 'teams '.($teams ? 'on' : 'off'));

            return;
        }

        // Teams can't be switched on under existing permission tables by editing config alone.
        if ($teams && $detected['permission']['tables'] && ! Schema::hasColumn(config('permission.table_names.roles', 'roles'), config('permission.column_names.team_foreign_key', 'team_id'))) {
            $this->step('permission-teams', 'manual', "set 'teams' => true in config/permission.php, then run php artisan permission:setup-teams and migrate");
            $this->blocked = true;

            return;
        }

        if ($this->option('dry-run')) {
            $this->step('permission-teams', 'would', 'set teams '.($teams ? 'on' : 'off'));

            return;
        }

        $path = config_path('permission.php');
        $contents = (string) file_get_contents($path);
        $contents = (string) preg_replace("/'teams'\\s*=>\\s*(true|false)/", "'teams' => ".($teams ? 'true' : 'false'), $contents, 1);
        file_put_contents($path, $contents);
        config()->set('permission', require $path);
        app(PermissionRegistrar::class)->teams = $teams;

        $this->step('permission-teams', 'done', 'teams '.($teams ? 'on' : 'off'));
    }

    /** @param  array<string, mixed>  $detected */
    protected function permissionMigrations(array $detected): void
    {
        if ($detected['permission']['migrations_published'] || $detected['permission']['tables']) {
            $this->step('permission-migrations', 'already', 'create_permission_tables');
        } elseif ($this->option('dry-run')) {
            $this->step('permission-migrations', 'would', 'publish create_permission_tables');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => 'permission-migrations']);
            $this->step('permission-migrations', 'done', 'published create_permission_tables');
        }
    }

    /**
     * Writing config/concierge.php is what marks concierge as set up. The
     * provider then registers in this same process, so the migrations and roles
     * that follow run against the new config.
     *
     * @param  array<string, mixed>  $detected
     * @param  array<string, mixed>  $choices
     */
    protected function conciergeConfig(array $detected, array $choices): void
    {
        if ($detected['concierge']['config_published']) {
            $this->step('concierge-config', 'already', "config/concierge.php, mode {$choices['mode']}");

            return;
        }

        if ($this->option('dry-run')) {
            $this->step('concierge-config', 'would', "write config/concierge.php, mode {$choices['mode']}");

            return;
        }

        $contents = (string) file_get_contents(__DIR__.'/../../config/concierge.php');
        $set = function (string $pattern, string $value) use (&$contents): void {
            $contents = (string) preg_replace($pattern, '${1}'.str_replace('\\', '\\\\', $value), $contents, 1);
        };
        $set("/('mode' => )null/", var_export($choices['mode'], true));
        $set("/('engine' => )'concierge'/", var_export($choices['engine'], true));
        $set("/('user' => )null/", $choices['user_model'] ? '\\'.ltrim($choices['user_model'], '\\').'::class' : 'null');
        $set("/('workspace' => )null/", $choices['workspace_model'] ? '\\'.ltrim($choices['workspace_model'], '\\').'::class' : 'null');
        $set("/('local_login' => \\[\\s*'enabled' => )false/", $choices['local_login'] ? 'true' : 'false');

        file_put_contents(config_path('concierge.php'), $contents);
        config()->set('concierge', require config_path('concierge.php'));
        $this->laravel->register(ConciergeServiceProvider::class, force: true);

        $this->step('concierge-config', 'done', "wrote config/concierge.php, mode {$choices['mode']}");

        if ($choices['local_login']) {
            $this->step('local-login', 'manual', 'set local_login.account.email in config/concierge.php');
        }
    }

    /**
     * Concierge never rewrites the app's models; it says exactly what to add.
     *
     * @param  class-string  $contract
     */
    protected function contract(string $step, ?string $model, string $contract, string $add): void
    {
        if (! $model || ! class_exists($model)) {
            $this->step($step, 'manual', 'no model found; set it in config/concierge.php');
            $this->blocked = true;

            return;
        }

        if (is_a($model, Model::class, true) && (! (new $model)->getIncrementing() || (new $model)->getKeyType() !== 'int')) {
            $this->step($step, 'manual', "{$model} needs an incrementing integer key");
            $this->blocked = true;

            return;
        }

        if (is_a($model, $contract, true)) {
            $this->step($step, 'already', "{$model} implements ".class_basename($contract));

            return;
        }

        $this->step($step, 'manual', "add to {$model}:\n{$add}");
        $this->blocked = true;
    }

    protected function migrateAndSyncRoles(): void
    {
        if ($this->blocked) {
            $this->step('migrate', 'blocked', 'finish the manual steps, then run php artisan concierge:install again');
            $this->step('roles', 'blocked', '');

            return;
        }

        if ($this->option('no-migrate') || $this->option('dry-run')) {
            $status = $this->option('dry-run') ? 'would' : 'skipped';
            $this->step('migrate', $status, 'php artisan migrate');
            $this->step('roles', $status, 'php artisan concierge:sync-roles');

            return;
        }

        try {
            $migrator = $this->laravel->make('migrator');
            $pending = $migrator->repositoryExists()
                ? array_diff(array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')])), $migrator->getRepository()->getRan())
                : ['all'];

            $this->callSilently('migrate', ['--force' => true]);
            $this->step('migrate', $pending === [] ? 'already' : 'done', $pending === [] ? 'nothing to migrate' : 'migrated');

            ConciergeModels::assertModeSupported();
            $missing = array_values(array_filter(Roles::configured(), fn (string $name) => ! Roles::find($name)));
            SyncRoleDefinitions::run();
            $this->step('roles', $missing === [] ? 'already' : 'done', implode(', ', $missing === [] ? Roles::configured() : $missing));
        } catch (Throwable $exception) {
            $this->step('migrate', 'manual', $exception->getMessage());
            $this->blocked = true;
        }
    }

    /** @param  array<string, mixed>  $choices */
    protected function firstOwner(array $choices): void
    {
        $owner = $this->blocked || $this->option('dry-run') || $this->option('no-migrate') ? false : rescue(
            fn () => Roles::find(config('concierge.owner_role', 'owner'))?->users()->exists() ?? false,
            false,
            report: false,
        );

        $how = $choices['mode'] === 'workspaces'
            ? 'set CONCIERGE_OWNERS and run php artisan concierge:owners --workspace=<key>'
            : 'set CONCIERGE_OWNERS and run php artisan concierge:owners';

        $this->step('first-owner', $owner ? 'already' : 'manual', $owner ? 'an owner exists' : $how);
    }

    /** @param  array<string, mixed>  $detected */
    protected function frontend(array $detected): void
    {
        $detail = match ($detected['starter_kit']) {
            'vue' => 'Vue starter kit: use the packaged pages, or copy them into the app with php artisan concierge:pages',
            'none' => 'no starter kit: use the actions and page props headless',
            default => "{$detected['starter_kit']} starter kit: use the actions and page props headless; native pages are planned",
        };

        $this->step('frontend', 'info', $detail);
    }

    protected function step(string $step, string $status, string $detail): void
    {
        $this->steps[] = compact('step', 'status', 'detail');
    }

    /** @param  array<string, mixed>  $result */
    protected function report(array $result): void
    {
        $d = $result['detected'];
        $this->line(sprintf(
            'Detected: %s starter kit%s, sign-in %s, user %s (%s key), team model %s.',
            $d['starter_kit'],
            $d['authkit'] ? ' (WorkOS AuthKit)' : '',
            $d['sign_in'] ?? 'none',
            $d['user_model'] ?? 'none',
            $d['user_key'] ?? 'no',
            $d['team_model'] ?? 'none',
        ));

        foreach ($result['steps'] as $step) {
            [$first, $more] = array_pad(explode("\n", $step['detail'], 2), 2, null);
            $this->line("{$step['step']}: {$step['status']}".($first !== '' ? " ({$first})" : ''));

            foreach ($more === null ? [] : explode("\n", $more) as $line) {
                $this->line("    {$line}");
            }
        }

        if ($result['set_up']) {
            $this->components->info('Concierge is set up.');
        } elseif ($this->blocked) {
            $this->components->warn('Finish the manual steps above, then run php artisan concierge:install again.');
        }
    }

    /** @param  array<string, mixed>  $detected */
    protected function refuse(string $message, array $detected): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message, 'detected' => $detected], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
