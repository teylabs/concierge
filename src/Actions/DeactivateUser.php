<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\UserDeactivated;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ManagedAccount;
use Tey\Concierge\Support\UserSessions;

/**
 * Takes a user's access away and keeps everything else: the user row, their
 * roles and, in workspace mode, their memberships. Sign-in is refused (see
 * RefuseDeactivatedUser), the EnsureAccountActive middleware signs out whoever
 * is still signed in, database sessions are deleted and the remember-me token
 * is cycled. API tokens are not deleted, so RestoreUser brings them back.
 *
 * The same rules as removal apply, through the AccountPolicy: not yourself,
 * not the last owner, not someone of equal or higher rank, and the
 * AccountGuard's veto. Sessions on other drivers end through the middleware.
 */
class DeactivateUser
{
    use Runnable;

    public const REASON_LENGTH = 500;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, Member $user, ?string $reason = null): Account
    {
        if (! $user instanceof Authenticatable) {
            throw new UnsupportedConfiguration('DeactivateUser needs an Authenticatable user model.');
        }

        return ManagedAccount::mutate($actor, $user, function (Account $actorAccount, ?Account $account) use ($actor, $user, $reason) {
            /** @var Account $account */
            $this->policy->authorizeManagement($actor, $user)->authorize();

            if (! $account->isActive()) {
                Response::deny(__('That account is already deactivated.'), 'already_deactivated')->authorize();
            }

            $reason = filled($reason) ? mb_substr(trim($reason), 0, self::REASON_LENGTH) : null;

            $account->forceFill([
                'status' => AccountStatus::Deactivated,
                'deactivated_at' => now(),
                'deactivated_by_id' => $actor->getKey(),
                'deactivation_reason' => $reason,
            ])->save();

            UserSessions::cycleRememberToken($user);
            $user->save();

            $sessionsEnded = UserSessions::endDatabaseSessions($user);

            UserDeactivated::dispatch((int) $user->getKey(), $actor->getKey(), $reason, $sessionsEnded);

            return $account;
        });
    }
}
