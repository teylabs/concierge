<?php

namespace Tey\Concierge\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Exceptions\CommandRefused;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\Roles;

/**
 * Changes a user's app-wide roles from the terminal as a named actor: the same
 * ChangeUserRoles action the Users page calls, with --as naming the user it
 * runs as and --role naming what it grants. Every rule the action applies
 * holds unchanged, so the terminal is a shortcut for the rules, never around
 * them.
 */
class RolesCommand extends Command
{
    public $signature = 'concierge:roles
        {email : The email of the user whose roles change}
        {--role=* : A role the user ends up with; at least one required}
        {--as= : The email of the existing user to run the action as}
        {--dry-run : Run the action checks, writing nothing}
        {--json : Print user, outcome and roles as JSON}';

    public $description = 'Change a user\'s app-wide roles through ChangeUserRoles. Runs as the --as user.';

    public function handle(AccountPolicy $policy, AppScope $scope): int
    {
        try {
            ConciergeModels::assertModeSupported();
            ConciergeModels::assertAppWide("concierge:roles is app-wide only; in workspace mode change roles through ChangeMemberRoles, or set 'mode' => 'app' in config/concierge.php");

            $actor = $this->resolve((string) $this->option('as'));
            $user = $this->user((string) $this->argument('email'));
            $grants = new GrantSet($this->roles());

            $roles = Roles::sort($grants->roles);
            $changed = $scope->roleNames($user) !== $roles;

            if ($this->option('dry-run')) {
                // The action's own authorization check, without its writes or events.
                $policy->authorizeGrant($actor, $grants, $user)->authorize();
                $outcome = $changed ? 'would_change' : 'unchanged';
            } else {
                ChangeUserRoles::run($actor, $user, $grants);
                $outcome = $changed ? 'changed' : 'unchanged';
            }
        } catch (AuthorizationException $exception) {
            return $this->refuse($exception->getMessage(), (string) $exception->getCode());
        } catch (CommandRefused $refused) {
            return $this->refuse($refused->getMessage(), $refused->errorCode);
        } catch (UnsupportedConfiguration $exception) {
            return $this->refuse($exception->getMessage(), 'unsupported_configuration');
        } catch (QueryException $exception) {
            return $this->refuse(
                $exception->getMessage().' Check database.default in config/database.php and run php artisan migrate before retrying php artisan concierge:roles.',
                'database',
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'user' => $user->conciergeEmail(),
                'outcome' => $outcome,
                'roles' => $roles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line("{$user->conciergeEmail()}: ".str_replace('_', ' ', $outcome).'; roles: '.implode(', ', $roles));
        }

        return self::SUCCESS;
    }

    /**
     * The --as user: an existing user the action runs as, matched ignoring
     * case. A refusal names the option, so the command is not a way around
     * the action's actor rules.
     *
     * @return Model&Member
     */
    protected function resolve(string $email): Member
    {
        $email = trim($email);

        if ($email === '') {
            throw new CommandRefused(
                'concierge:roles needs --as=<email> naming the existing user to run the action as.',
                'missing_actor',
            );
        }

        /** @var class-string<Model&Member> $model */
        $model = ConciergeModels::user();

        /** @var (Model&Member)|null $actor */
        $actor = $model::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if (! $actor instanceof Member) {
            throw new CommandRefused(
                "No user found for [{$email}]. The --as user must exist.",
                'unknown_actor',
            );
        }

        return $actor;
    }

    /**
     * The target user an email names, matched ignoring case.
     *
     * @return Model&Member
     */
    protected function user(string $email): Member
    {
        $email = trim($email);

        if ($email === '') {
            throw new CommandRefused(
                'concierge:roles needs the email of the user whose roles change.',
                'missing_user',
            );
        }

        /** @var class-string<Model&Member> $model */
        $model = ConciergeModels::user();

        /** @var (Model&Member)|null $target */
        $target = $model::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if (! $target instanceof Member) {
            throw new CommandRefused(
                "No user found for [{$email}].",
                'unknown_user',
            );
        }

        return $target;
    }

    /**
     * The --role values: at least one, each a configured concierge role.
     *
     * @return list<string>
     */
    protected function roles(): array
    {
        $roles = array_values(array_unique(array_filter(
            array_map('strval', (array) $this->option('role')),
            fn (string $role) => $role !== '',
        )));

        if ($roles === []) {
            throw new CommandRefused('concierge:roles needs at least one --role=<role>, e.g. --role=presenter.', 'missing_roles');
        }

        foreach ($roles as $role) {
            if (Roles::rank($role) === null) {
                throw new CommandRefused("[{$role}] is not a concierge role; use one of the roles in config/concierge.php (".implode(', ', Roles::configured()).').', 'unknown_role');
            }
        }

        return $roles;
    }

    /**
     * Every refusal exits non-zero with the message that names its fix, and as
     * {error, code} under --json.
     */
    protected function refuse(string $message, string $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message, 'code' => $code], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
