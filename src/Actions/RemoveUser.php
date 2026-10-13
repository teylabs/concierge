<?php

namespace Tey\Concierge\Actions;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserRemoved;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\ManagedAccount;

/**
 * App-wide mode's RemoveMembership. With no workspace to leave, removal is
 * leaving the app: roles and permissions are cleared, the account row is
 * deleted, and then the user is deleted through the model's own delete(),
 * so a user model with SoftDeletes keeps its row. Authored content and other
 * tables are the host's business; listen to UserRemoved.
 */
class RemoveUser
{
    use Runnable;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, Member $user): Member
    {
        ConciergeModels::assertAppWide('use RemoveMembership');

        if (! $user instanceof Model) {
            throw new UnsupportedConfiguration('RemoveUser needs an Eloquent user model; pass an instance of the model set as concierge.models.user.');
        }

        return ManagedAccount::mutate($actor, $user, function (Account $actorAccount, ?Account $account) use ($actor, $user) {
            $this->policy->authorizeManagement($actor, $user)->authorize();

            $user->syncRoles([]);
            $user->syncPermissions([]);

            $account?->delete();
            $user->delete();

            UserRemoved::dispatch((int) $user->getKey(), $actor->getKey());

            return $user;
        });
    }
}
