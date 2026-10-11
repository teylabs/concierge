<?php

namespace Tey\Concierge\Actions;

use SensitiveParameter;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationInspection;
use Tey\Concierge\Support\InvitationRules;

/**
 * Read-only preview for the acceptance page. Never authoritative:
 * AcceptInvitation re-validates everything under the workspace lock.
 */
class InspectInvitation
{
    use Runnable;

    public function __construct(protected Concierge $concierge) {}

    public function handle(?Member $user, #[SensitiveParameter] string $token): InvitationInspection
    {
        $invitation = ConciergeModels::invitation()::query()->where('token_hash', Invitation::hashToken($token))->first();

        if (! $invitation) {
            return new InvitationInspection(InvitationState::Invalid);
        }

        $workspace = $invitation->workspace;

        if ($invitation->status === InvitationStatus::Accepted) {
            $state = $user && (int) $invitation->accepted_by_id === (int) $user->getKey()
                ? InvitationState::AlreadyMember
                : InvitationState::Accepted;

            return new InvitationInspection($state, $invitation);
        }

        if (! $user) {
            $state = match (true) {
                $invitation->status === InvitationStatus::Revoked => InvitationState::Revoked,
                $invitation->isExpired() => InvitationState::Expired,
                ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists() => InvitationState::SignInRequired,
                default => InvitationState::RegisterRequired,
            };

            return new InvitationInspection($state, $invitation);
        }

        $state = InvitationRules::evaluate($invitation, $user, $this->concierge->membership($user, $workspace), $workspace);

        return new InvitationInspection($state ?? InvitationState::Ready, $invitation);
    }
}
