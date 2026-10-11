<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\TenantInvitationRevoked;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\PlatformOwners;

class RevokeTenantInvitation
{
    use Runnable;

    public function handle(Member $actor, TenantInvitation $invitation): TenantInvitation
    {
        PlatformOwners::authorize($actor);

        return DB::transaction(function () use ($actor, $invitation) {
            $invitation = ConciergeModels::tenantInvitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

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

            TenantInvitationRevoked::dispatch($invitation->getKey(), $actor->getKey());

            return $invitation;
        });
    }
}
