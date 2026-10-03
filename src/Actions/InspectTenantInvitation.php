<?php

namespace Tey\Concierge\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use SensitiveParameter;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\TenantInvitationInspection;

/**
 * Read-only preview so the host can lock its register form to the invited email.
 * Never authoritative: AcceptTenantInvitation re-validates under lock.
 */
class InspectTenantInvitation
{
    use AsAction;

    public function handle(?Member $user, #[SensitiveParameter] string $token): TenantInvitationInspection
    {
        $invitation = ConciergeModels::tenantInvitation()::query()->where('token_hash', TenantInvitation::hashToken($token))->first();

        if (! $invitation) {
            return new TenantInvitationInspection(InvitationState::Invalid);
        }

        if ($user && (int) $invitation->accepted_by_id === (int) $user->getKey() && $invitation->accepted_at) {
            return new TenantInvitationInspection(InvitationState::AlreadyMember, $invitation);
        }

        $state = AcceptTenantInvitation::evaluate($invitation, $user);

        if ($state === null) {
            $state = match (true) {
                $user !== null => InvitationState::Ready,
                ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists() => InvitationState::SignInRequired,
                default => InvitationState::RegisterRequired,
            };
        }

        return new TenantInvitationInspection($state, $invitation);
    }
}
