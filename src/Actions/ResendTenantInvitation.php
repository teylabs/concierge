<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\TenantInvitationResent;
use Tey\Concierge\Jobs\DeliverTenantInvitation;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedTenantInvitation;
use Tey\Concierge\Support\PlatformOwners;

/**
 * Rotates the token (old links die) and renews expiry. A lapsed-but-unaccepted
 * invitation can be resent; an accepted or revoked one cannot.
 */
class ResendTenantInvitation
{
    use Runnable;

    public function handle(Member $actor, TenantInvitation $invitation): IssuedTenantInvitation
    {
        PlatformOwners::authorize($actor);

        return DB::transaction(function () use ($actor, $invitation) {
            $invitation = ConciergeModels::tenantInvitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => __('Only pending invitations can be resent.')]);
            }

            $cooldown = (int) config('concierge.invitations.resend_cooldown_seconds', 60);

            if (($invitation->rotated_at ?? $invitation->created_at)->copy()->addSeconds($cooldown)->isFuture()) {
                Response::deny(__('Please wait before resending this invitation.'), 'cooldown')->authorize();
            }

            $token = InvitationTokens::generate();

            $invitation->forceFill([
                'token_hash' => $token['hash'],
                'generation' => $invitation->generation + 1,
                'rotated_at' => now(),
                'expires_at' => InvitationTokens::expiry(),
            ])->save();

            TenantInvitationResent::dispatch($invitation->getKey(), $invitation->generation, $actor->getKey());
            DeliverTenantInvitation::queueFor($invitation, $token['token']);

            return new IssuedTenantInvitation($invitation, $token['token']);
        });
    }
}
