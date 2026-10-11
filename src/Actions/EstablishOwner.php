<?php

namespace Tey\Concierge\Actions;

use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\OwnerEstablished;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\ManagedAccount;
use Tey\Concierge\Support\Roles;

/**
 * Makes a user an app-wide owner: the owner role, replacing any other roles,
 * and an active account row. Idempotent, and several owners are allowed, so
 * the first-owner command and a deploy script can both call it freely. There
 * is no actor: this is the operator's or the installer's act, not a manager's.
 */
class EstablishOwner
{
    use Runnable;

    public function __construct(protected AppScope $scope) {}

    public function handle(Member $user): Account
    {
        ConciergeModels::assertAppWide('use EstablishWorkspace');
        ConciergeModels::assertModeSupported();

        return ManagedAccount::mutate($user, null, function (Account $account) use ($user) {
            $account->forceFill([
                'status' => AccountStatus::Active,
                'joined_at' => $account->joined_at ?? now(),
                'deactivated_at' => null,
                'deactivated_by_id' => null,
                'deactivation_reason' => null,
            ])->save();

            if (! $this->scope->isOwner($user)) {
                $user->syncRoles(Roles::resolve([Roles::owner()]));

                OwnerEstablished::dispatch((int) $user->getKey());
            }

            return $account;
        });
    }
}
