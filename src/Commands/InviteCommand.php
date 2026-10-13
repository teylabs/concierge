<?php

namespace Tey\Concierge\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\InviteUser;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Exceptions\CommandRefused;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\Roles;

/**
 * Invites an email into the app from the terminal as a named actor: the same
 * InviteUser action the Users page calls, with --as naming the user it runs as
 * and --role naming what it grants. Every rule the action applies holds
 * unchanged, so the terminal is a shortcut for the rules, never around them.
 */
class InviteCommand extends Command
{
    public $signature = 'concierge:invite
        {email : The email address to invite}
        {--role=* : A role to grant on acceptance; at least one required}
        {--as= : The email of the existing user to run the action as}
        {--dry-run : Resolve both users and run the action authorization check, writing nothing}
        {--json : Print email, outcome, invitationId, roles and expiresAt as JSON}';

    public $description = 'Invite an email into the app with roles. Runs as the --as user.';

    public function handle(AccountPolicy $policy): int
    {
        try {
            ConciergeModels::assertModeSupported();
            ConciergeModels::assertAppWide("concierge:invite is app-wide only; in workspace mode invite into a workspace with InviteMember, or set 'mode' => 'app' in config/concierge.php");

            $actor = $this->resolve((string) $this->option('as'));
            $email = $this->email();
            $grants = new GrantSet($this->roles());
            $target = ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($this->option('dry-run')) {
                // The action's authorization check, run against both users
                // without writing, mailing or dispatching events.
                $policy->authorizeGrant($actor, $grants, $target)->authorize();

                $outcome = 'would_invite';
                $invitationId = null;
                $expiresAt = InvitationTokens::expiry();
            } else {
                /** @var IssuedInvitation $issued */
                $issued = InviteUser::run($actor, $email, $grants);

                $outcome = 'invited';
                $invitationId = $issued->invitation->getKey();
                $expiresAt = $issued->invitation->expires_at;
            }
        } catch (AuthorizationException $exception) {
            return $this->refuse($exception->getMessage(), (string) $exception->getCode());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            return $this->refuse(implode(' ', array_map('strval', array_values($errors)[0])), (string) array_key_first($errors));
        } catch (CommandRefused $refused) {
            return $this->refuse($refused->getMessage(), $refused->errorCode);
        } catch (UnsupportedConfiguration $exception) {
            return $this->refuse($exception->getMessage(), 'unsupported_configuration');
        } catch (QueryException $exception) {
            return $this->refuse(
                $exception->getMessage().' Check database.default in config/database.php and run php artisan migrate before retrying php artisan concierge:invite.',
                'database',
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'email' => $email,
                'outcome' => $outcome,
                'invitationId' => $invitationId,
                'roles' => $grants->roles,
                'expiresAt' => $expiresAt->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("{$email}: ".($outcome === 'invited' ? 'invited' : 'would invite').' with role(s) '.implode(', ', $grants->roles).', '.($outcome === 'invited'
            ? "invitation {$invitationId} expires {$expiresAt->toIso8601String()}"
            : 'no invitation written'));

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
                'concierge:invite needs --as=<email> naming the existing user to run the action as.',
                'missing_actor',
            );
        }

        /** @var class-string<Model&Member> $model */
        $model = ConciergeModels::user();

        /** @var (Model&Member)|null $actor */
        $actor = $model::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        if (! $actor instanceof Member) {
            throw new CommandRefused(
                "No user found for [{$email}]. The --as user must exist.",
                'unknown_actor',
            );
        }

        return $actor;
    }

    /**
     * The email to invite, normalized and validated with the action's own rule.
     */
    protected function email(): string
    {
        $email = InvitationRules::normalizeEmail((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
        }

        return $email;
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
            throw new CommandRefused('concierge:invite needs at least one --role=<role>, e.g. --role=presenter.', 'missing_roles');
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
