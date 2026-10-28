<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\InvitationLinkCopied;
use Tey\Concierge\Events\InvitationResent;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\GrantSnapshot;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\ManagedAccount;
use Tey\Concierge\Support\ScopedGrants;

/**
 * Rotates the token (old links die), renews expiry and re-captures grants
 * under the resending manager's current authority. A message replaces the
 * invitation's; null keeps it. A scoped invitation keeps its record.
 */
class ResendInvitation
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy, protected AccountPolicy $accountPolicy) {}

    public function handle(Member $actor, Invitation $invitation, ?string $message = null): IssuedInvitation
    {
        return $this->rotate($actor, $invitation, InvitationRules::message($message), deliver: true);
    }

    /** @internal shared with CopyInvitationLink */
    public function rotate(Member $actor, Invitation $invitation, ?string $message, bool $deliver): IssuedInvitation
    {
        $mutate = function () use ($actor, $invitation, $message, $deliver) {
            $workspace = $invitation->isAppWide() ? null : Locks::workspace($invitation->workspace_id);
            $invitation = ConciergeModels::invitation()::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $grants = new GrantSet($invitation->roleNames());

            (match (true) {
                $workspace === null => $this->accountPolicy->authorizeGrant($actor, $grants),
                $invitation->isScoped() => ScopedGrants::authorize($this->policy, $actor, $workspace, $grants),
                default => $this->policy->authorizeGrant($actor, $workspace, $grants),
            })->authorize();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => $deliver
                    ? __('Only pending invitations can be resent.')
                    : __('Only pending invitations have a link to copy.')]);
            }

            $cooldown = (int) config('concierge.invitations.resend_cooldown_seconds', 60);

            if ($deliver && ($invitation->rotated_at ?? $invitation->created_at)->copy()->addSeconds($cooldown)->isFuture()) {
                Response::deny(__('Please wait before resending this invitation.'), 'cooldown')->authorize();
            }

            $snapshot = GrantSnapshot::capture($grants, $invitation->scope())
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
                ...($message === null ? [] : ['message' => $message]),
            ])->save();

            if ($deliver) {
                InvitationResent::dispatch($workspace?->getKey(), $invitation->getKey(), $invitation->generation, $actor->getKey());
                DeliverInvitation::queueFor($invitation, $token['token']);
            } else {
                InvitationLinkCopied::dispatch($workspace?->getKey(), $invitation->getKey(), $invitation->generation, $actor->getKey());
            }

            return new IssuedInvitation($invitation, $token['token']);
        };

        return $invitation->isAppWide()
            ? ManagedAccount::mutate($actor, null, $mutate)
            : DB::transaction($mutate);
    }
}
