<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\InvitationResent;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\GrantSnapshot;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\ManagedAccount;

/**
 * Rotates the token (old links die), renews expiry and re-captures grants
 * under the resending manager's current authority.
 */
class ResendInvitation
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy, protected AccountPolicy $accountPolicy) {}

    public function handle(Member $actor, Invitation $invitation): IssuedInvitation
    {
        $mutate = function () use ($actor, $invitation) {
            $workspace = $invitation->isAppWide() ? null : Locks::workspace($invitation->workspace_id);
            $invitation = ConciergeModels::invitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $grants = new GrantSet($invitation->roleNames());

            ($workspace
                ? $this->policy->authorizeGrant($actor, $workspace, $grants)
                : $this->accountPolicy->authorizeGrant($actor, $grants))->authorize();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => __('Only pending invitations can be resent.')]);
            }

            $cooldown = (int) config('concierge.invitations.resend_cooldown_seconds', 60);

            if (($invitation->rotated_at ?? $invitation->created_at)->copy()->addSeconds($cooldown)->isFuture()) {
                Response::deny(__('Please wait before resending this invitation.'), 'cooldown')->authorize();
            }

            $snapshot = GrantSnapshot::capture($grants)
                ?? throw ValidationException::withMessages(['roles' => __('One or more roles no longer exist.')]);

            $token = InvitationTokens::generate();

            $invitation->forceFill([
                'token_hash' => $token['hash'],
                'generation' => $invitation->generation + 1,
                'rotated_at' => now(),
                'expires_at' => InvitationTokens::expiry(),
                'grants' => $snapshot['grants'],
                'grants_fingerprint' => $snapshot['fingerprint'],
                'inviter_id' => $actor->getKey(),
            ])->save();

            InvitationResent::dispatch($workspace?->getKey(), $invitation->getKey(), $invitation->generation, $actor->getKey());
            DeliverInvitation::queueFor($invitation, $token['token']);

            return new IssuedInvitation($invitation, $token['token']);
        };

        return $invitation->isAppWide()
            ? ManagedAccount::mutate($actor, null, $mutate)
            : DB::transaction($mutate);
    }
}
