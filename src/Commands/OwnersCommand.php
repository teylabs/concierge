<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;

class OwnersCommand extends Command
{
    public $signature = 'concierge:owners
        {--workspace= : The key of an existing workspace (required in workspace mode)}
        {--dry-run : Preview without writing or sending password links}
        {--json : Print email, outcome and linkSent for each configured owner}';

    public $description = 'Create configured owners and send new users a set-password link. Safe to run on every deploy.';

    public function handle(Concierge $concierge): int
    {
        try {
            ConciergeModels::assertModeSupported();
            $emails = array_values(array_unique(array_filter(array_map(
                fn (string $email) => Str::lower(trim($email)),
                config('concierge.owners', []),
            ), fn (string $email) => $email !== '')));

            if (Validator::make(['owners' => $emails], ['owners.*' => ['required', 'email', 'max:255']])->fails()) {
                throw new LogicException('concierge.owners must contain valid email addresses; check CONCIERGE_OWNERS.');
            }

            $workspace = $this->workspace($emails);
            $results = [];

            foreach ($emails as $email) {
                $process = function () use ($concierge, $workspace, $email) {
                    // Use the same lock as EstablishWorkspace, before creating the user.
                    $locked = $workspace && ! $this->option('dry-run') ? Locks::workspace($workspace->getKey()) : $workspace;

                    return $this->establish($concierge, $email, $locked);
                };

                $results[] = $this->option('dry-run') ? $process() : DB::transaction($process);
            }
        } catch (LogicException|RuntimeException $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($results === []) {
            $this->components->info('No configured owners; nothing to do.');
        } else {
            foreach ($results as $result) {
                $outcome = str_replace('_', ' ', $result['outcome']);
                $link = $result['linkSent'] ? 'set-password link sent' : 'no link sent';
                $this->line("{$result['email']}: {$outcome}, {$link}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $emails
     * @return (Model&Workspace)|null
     */
    protected function workspace(array $emails): ?Workspace
    {
        if (Mode::configured() === Mode::App) {
            return null;
        }

        $key = $this->option('workspace');

        if ($key === null || $key === '') {
            throw new LogicException('Workspace mode requires --workspace=<key> naming an existing workspace.');
        }

        $workspace = filter_var($key, FILTER_VALIDATE_INT) !== false
            ? ConciergeModels::workspace()::query()->find($key)
            : null;

        if (! $workspace) {
            throw new LogicException("No workspace found for --workspace={$key}; pass --workspace=<key> naming an existing workspace.");
        }

        Locks::assertManaged($workspace);

        if (count($emails) > 1) {
            throw new LogicException('A --workspace has exactly one owner; configure one email in concierge.owners.');
        }

        return $workspace;
    }

    /** @return array{email: string, outcome: string, linkSent: bool} */
    protected function establish(Concierge $concierge, string $email, ?Workspace $workspace): array
    {
        $model = ConciergeModels::user();
        $user = $model::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $created = $user === null;

        if ($workspace) {
            $owner = $concierge->ownerOf($workspace);

            if ($owner && (! $user || (int) $owner->user_id !== (int) $user->getKey())) {
                throw new LogicException("--workspace={$workspace->getKey()} already has another owner; ownership is unchanged.");
            }

            $alreadyOwner = $owner !== null;
        } else {
            $alreadyOwner = $user && $concierge->isOwner($user);
        }

        if ($alreadyOwner) {
            return ['email' => $email, 'outcome' => 'already_owner', 'linkSent' => false];
        }

        if ($this->option('dry-run')) {
            return ['email' => $email, 'outcome' => $created ? 'would_create' : 'would_add_role', 'linkSent' => false];
        }

        SyncRoleDefinitions::run();

        if ($created) {
            /** @var Model&Member $user */
            $user = new $model;

            if (! $user instanceof Authenticatable || ! $user instanceof CanResetPassword) {
                throw new LogicException('concierge:owners needs a user model that is Authenticatable and CanResetPassword.');
            }

            // Hash and discard a random value: no operator knows the password.
            $user->forceFill([
                'name' => $email,
                'email' => $email,
                $user->getAuthPasswordName() => Hash::make(Str::random(64)),
            ])->save();
        }

        if ($workspace) {
            EstablishWorkspace::run($workspace, $user);
        } else {
            EstablishOwner::run($user);
        }

        // Existing users are never emailed, including owners who have not used their link.
        if ($created) {
            $status = Password::broker()->sendResetLink(['email' => $email]);

            if ($status !== Password::ResetLinkSent) {
                throw new RuntimeException("Password broker did not send a set-password link to {$email} ({$status}); this owner's changes were rolled back.");
            }
        }

        return ['email' => $email, 'outcome' => $created ? 'created' : 'role_added', 'linkSent' => $created];
    }
}
