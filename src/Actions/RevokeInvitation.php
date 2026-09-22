<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\InvitationRevoked;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;

class RevokeInvitation
{
    use AsAction;

    public function __construct(protected GrantPolicy $policy) {}

    public function handle(Member $actor, Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($actor, $invitation) {
            $workspace = Locks::workspace($invitation->workspace_id);
            $invitation = ConciergeModels::invitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            $this->policy->authorizeManagement($actor, $workspace)->authorize();

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

            InvitationRevoked::dispatch($workspace->getKey(), $invitation->getKey(), $actor->getKey());

            return $invitation;
        });
    }
}
