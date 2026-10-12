<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\InvitationRevoked;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\ManagedAccount;

class RevokeInvitation
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy, protected AccountPolicy $accountPolicy) {}

    public function handle(Member $actor, Invitation $invitation): Invitation
    {
        $mutate = function () use ($actor, $invitation) {
            $workspace = $invitation->isAppWide() ? null : Locks::workspace($invitation->workspace_id);
            $invitation = ConciergeModels::invitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            ($workspace
                ? $this->policy->authorizeManagement($actor, $workspace)
                : $this->accountPolicy->authorizeManagement($actor))->authorize();

            if ($invitation->status === InvitationStatus::Revoked) {
                return $invitation;
            }

            if ($invitation->status === InvitationStatus::Accepted) {
                throw ValidationException::withMessages(['invitation' => __('This invitation has already been accepted.')]);
            }

            $invitation->forceFill([
                'status' => InvitationStatus::Revoked,
                'pending_key' => null,
                'revoked_at' => now(),
                'revoked_by_id' => $actor->getKey(),
            ])->save();

            InvitationRevoked::dispatch($workspace?->getKey(), $invitation->getKey(), $actor->getKey());

            return $invitation;
        };

        return $invitation->isAppWide()
            ? ManagedAccount::mutate($actor, null, $mutate)
            : DB::transaction($mutate);
    }
}
