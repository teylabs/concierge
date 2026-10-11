<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\PasswordResetForced;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ForcedPasswordReset;
use Tey\Concierge\Support\ManagedMembership;

/**
 * Locks an account down when it may be compromised: the password is replaced
 * with one nobody knows, every session and the remember-me token end, two-factor
 * is optionally cleared, and the owner receives a password reset link.
 *
 * The target is a membership because the actor's authority comes from a
 * workspace: the same manager and rank rules as suspending or removing apply,
 * and nobody can reset their own password this way.
 *
 * Sessions: with the `database` session driver the user's rows are deleted.
 * Other drivers cannot enumerate a user's sessions; there the password change
 * itself ends them, provided the host runs Laravel's AuthenticateSession
 * middleware, which signs out a session whose password hash no longer matches.
 * API tokens (Sanctum, Passport) are not touched; listen to PasswordResetForced.
 */
class ForcePasswordReset
{
    use Runnable;

    public function __construct(
        protected GrantPolicy $policy,
        protected RecentAuthentication $recentAuthentication,
        protected AccountGuard $accountGuard,
        protected PasswordBrokerFactory $brokers,
    ) {}

    public function handle(Member $actor, Membership $membership, bool $resetTwoFactor = false): ForcedPasswordReset
    {
        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor, $resetTwoFactor) {
            $this->policy->authorizeManagement($actor, $workspace, $membership)->authorize();

            if ($membership->status === MembershipStatus::Removed) {
                Response::deny(__('That person is no longer a member.'), 'not_member')->authorize();
            }

            if (config('concierge.recent_authentication_seconds') !== null && ! $this->recentAuthentication->confirmed($actor)) {
                Response::deny(__('Please confirm your password first.'), 'recent_auth_required')->authorize();
            }

            $user = $membership->user;

            $this->accountGuard->authorize(AccountGuard::FORCE_PASSWORD_RESET, $actor, $user)->authorize();

            if (! $user instanceof Authenticatable || ! $user instanceof CanResetPassword) {
                throw new UnsupportedConfiguration(
                    'ForcePasswordReset needs a user model that is Authenticatable and CanResetPassword.'
                );
            }

            $twoFactorColumns = $resetTwoFactor ? $this->twoFactorColumns($user) : [];

            // The random value is hashed and discarded: no one, including this process
            // after this line, can sign in with it.
            $user->forceFill([
                $user->getAuthPasswordName() => Hash::make(Str::random(64)),
                ...array_fill_keys($twoFactorColumns, null),
            ]);

            if ($user->getRememberTokenName()) {
                $user->setRememberToken(Str::random(60));
            }

            $user->save();

            $sessionsEnded = $this->endDatabaseSessions($user);

            $broker = $this->brokers->broker(config('concierge.forced_password_reset.broker'));

            // createToken() is on Laravel's broker, not the contract: a custom broker
            // without it cannot deliver the link, so stop rather than lock the account out silently.
            if (! $broker instanceof PasswordBroker) {
                throw new UnsupportedConfiguration('ForcePasswordReset needs a password broker that can create a token for a user.');
            }

            $user->sendPasswordResetNotification($broker->createToken($user));

            PasswordResetForced::dispatch(
                $workspace->getKey(),
                $membership->getKey(),
                $user->getKey(),
                $actor->getKey(),
                $twoFactorColumns !== [],
                $sessionsEnded,
            );

            return new ForcedPasswordReset($membership, $twoFactorColumns !== [], $sessionsEnded);
        });
    }

    /**
     * The configured two-factor columns that exist on the user table. Asking to
     * reset two-factor on an app without any is a misconfiguration, not a no-op.
     *
     * @return list<string>
     */
    protected function twoFactorColumns(Model $user): array
    {
        $configured = array_values(array_filter((array) config('concierge.forced_password_reset.two_factor_columns', [])));
        $existing = Schema::connection($user->getConnectionName())->getColumnListing($user->getTable());
        $columns = array_values(array_intersect($configured, $existing));

        if ($columns === []) {
            throw new UnsupportedConfiguration(
                'Two-factor reset was requested but none of the configured two_factor_columns exist on the user table.'
            );
        }

        return $columns;
    }

    /**
     * Delete the user's rows from the sessions table when sessions live in the
     * database; null otherwise, since other drivers cannot list a user's sessions.
     */
    protected function endDatabaseSessions(Authenticatable $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
