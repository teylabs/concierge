<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\UserRestored;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ManagedAccount;

/**
 * Reverses DeactivateUser: sign-in works again, the roles are as the user left
 * them, API tokens that were blocked work again, and no invitation is sent.
 * The same guards apply as for deactivating.
 */
class RestoreUser
{
    use Runnable;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, Member $user): Account
    {
        return ManagedAccount::mutate($actor, $user, function (Account $actorAccount, ?Account $account) use ($actor, $user) {
            /** @var Account $account */
            $this->policy->authorizeManagement($actor, $user)->authorize();

            if ($account->isActive()) {
                Response::deny(__('That account is not deactivated.'), 'not_deactivated')->authorize();
            }

            $account->forceFill([
                'status' => AccountStatus::Active,
                'deactivated_at' => null,
                'deactivated_by_id' => null,
                'deactivation_reason' => null,
            ])->save();

            UserRestored::dispatch((int) $user->getKey(), $actor->getKey());

            return $account;
        });
    }
}
