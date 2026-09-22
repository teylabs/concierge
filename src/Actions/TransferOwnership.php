<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Events\OwnershipTransferred;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

class TransferOwnership
{
    use AsAction;

    public function __construct(protected Concierge $concierge, protected RecentAuthentication $recentAuthentication) {}

    public function handle(Member $actor, Membership $recipient): Membership
    {
        return DB::transaction(function () use ($actor, $recipient) {
            $workspace = Locks::workspace($recipient->workspace_id);
            $ownership = Locks::ownership($workspace->getKey());

            if (! $ownership) {
                Response::deny(__('Only the workspace owner can transfer ownership.'), 'not_owner')->authorize();
            }

            $locked = Locks::memberships([$ownership->membership_id, $recipient->getKey()]);
            $current = $locked[$ownership->membership_id];
            $recipient = $locked[$recipient->getKey()] ?? null;

            if ((int) $current->user_id !== (int) $actor->getKey() || ! $current->isActive()) {
                Response::deny(__('Only the workspace owner can transfer ownership.'), 'not_owner')->authorize();
            }

            if (! $this->recentAuthentication->confirmed($actor)) {
                Response::deny(__('Please confirm your password first.'), 'recent_auth_required')->authorize();
            }

            $recipientUser = $recipient?->user;

            if (! $recipient
                || (int) $recipient->workspace_id !== (int) $workspace->getKey()
                || $recipient->is($current)
                || ! $recipient->isActive()
                || ! $recipientUser?->conciergeHasVerifiedEmail()) {
                Response::deny(__('Ownership can only pass to another active, verified member.'), 'recipient_invalid')->authorize();
            }

            $ownership->forceFill([
                'membership_id' => $recipient->getKey(),
                'previous_membership_id' => $current->getKey(),
                'transferred_at' => now(),
            ])->save();

            $currentUser = $current->user;

            $this->concierge->context()->run($workspace, function () use ($currentUser, $recipientUser) {
                $currentUser->syncRoles(Roles::resolve([config('concierge.transfer_demotes_to', 'admin')]));
                $recipientUser->syncRoles(Roles::resolve([Roles::owner()]));
            }, [$currentUser, $recipientUser]);

            OwnershipTransferred::dispatch($workspace->getKey(), $current->getKey(), $recipient->getKey(), $actor->getKey());

            return $recipient;
        });
    }
}
