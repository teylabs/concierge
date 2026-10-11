<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MemberInvited;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\GrantSnapshot;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\Locks;

class InviteMember
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy) {}

    public function handle(Member $actor, Workspace $workspace, string $email, GrantSet $grants): IssuedInvitation
    {
        $email = InvitationRules::normalizeEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
        }

        return DB::transaction(function () use ($actor, $workspace, $email, $grants) {
            $workspace = Locks::workspace($workspace->getKey());

            $this->policy->authorizeGrant($actor, $workspace, $grants)->authorize();

            $status = ConciergeModels::membership()::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('user_id', ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$email])->select((new (ConciergeModels::user()))->getKeyName()))
                ->first()?->status;

            if ($status === MembershipStatus::Active) {
                throw ValidationException::withMessages(['email' => __('That person is already a member.')]);
            }

            if ($status === MembershipStatus::Suspended) {
                throw ValidationException::withMessages(['email' => __('That member is suspended; reinstate them instead.')]);
            }

            $pending = ConciergeModels::invitation()::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('pending_key', $email)
                ->lockForUpdate()
                ->first();

            if ($pending && ! $pending->isExpired()) {
                throw ValidationException::withMessages(['email' => __('An invitation is already pending for that email.')]);
            }

            $pending?->forceFill(['status' => InvitationStatus::Expired, 'pending_key' => null])->save();

            $snapshot = GrantSnapshot::capture($grants)
                ?? throw ValidationException::withMessages(['roles' => __('One or more roles do not exist.')]);

            $token = InvitationTokens::generate();

            $invitation = ConciergeModels::invitation()::query()->create([
                'workspace_id' => $workspace->getKey(),
                'email' => $email,
                'pending_key' => $email,
                'grants' => $snapshot['grants'],
                'grants_fingerprint' => $snapshot['fingerprint'],
                'inviter_id' => $actor->getKey(),
                'token_hash' => $token['hash'],
                'generation' => 1,
                'status' => InvitationStatus::Pending,
                'expires_at' => InvitationTokens::expiry(),
            ]);

            MemberInvited::dispatch($workspace->getKey(), $invitation->getKey(), $actor->getKey());
            DeliverInvitation::queueFor($invitation, $token['token']);

            return new IssuedInvitation($invitation, $token['token']);
        });
    }
}
