<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use SensitiveParameter;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\InvitationAccepted;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\Acceptance;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

/**
 * Authoritative acceptance. A rejection throws and writes nothing.
 */
class AcceptInvitation
{
    use AsAction;

    public function __construct(protected Concierge $concierge) {}

    public function handle(Member $user, #[SensitiveParameter] string $token): Acceptance
    {
        $hash = Invitation::hashToken($token);

        $workspaceId = ConciergeModels::invitation()::query()->where('token_hash', $hash)->value('workspace_id')
            ?? throw new InvitationRejected(InvitationState::Invalid);

        return DB::transaction(function () use ($user, $hash, $workspaceId) {
            $workspace = Locks::workspace($workspaceId);

            $membership = ConciergeModels::membership()::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            $invitation = ConciergeModels::invitation()::query()
                ->where('token_hash', $hash)
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->first() ?? throw new InvitationRejected(InvitationState::Invalid);

            if ($invitation->status === InvitationStatus::Accepted
                && (int) $invitation->accepted_by_id === (int) $user->getKey()
                && $membership?->isActive()) {
                return new Acceptance($membership, AcceptanceOutcome::AlreadyMember);
            }

            $state = InvitationRules::evaluate($invitation, $user, $membership, $workspace);

            if ($state !== null && $state !== InvitationState::AlreadyMember) {
                throw new InvitationRejected($state);
            }

            if ($state === InvitationState::AlreadyMember) {
                return $this->consume($invitation, $membership, $user, AcceptanceOutcome::AlreadyMember);
            }

            $membership ??= ConciergeModels::membership()::make([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $user->getKey(),
            ]);

            $membership->forceFill([
                'status' => MembershipStatus::Active,
                'invitation_id' => $invitation->getKey(),
                'joined_at' => now(),
                'suspended_at' => null,
                'suspended_by_id' => null,
                'suspension_reason' => null,
                'removed_at' => null,
                'removed_by_id' => null,
            ])->save();

            $this->concierge->context()->run(
                $workspace,
                fn () => $user->syncRoles(Roles::resolve($invitation->roleNames())),
                [$user],
            );

            return $this->consume($invitation, $membership, $user, AcceptanceOutcome::Joined);
        });
    }

    protected function consume(Invitation $invitation, Membership $membership, Member $user, AcceptanceOutcome $outcome): Acceptance
    {
        $invitation->forceFill([
            'status' => InvitationStatus::Accepted,
            'pending_key' => null,
            'accepted_at' => now(),
            'accepted_by_id' => $user->getKey(),
            'accepted_membership_id' => $membership->getKey(),
        ])->save();

        InvitationAccepted::dispatch(
            $invitation->workspace_id,
            $invitation->getKey(),
            $membership->getKey(),
            $user->getKey(),
            $outcome->value,
        );

        return new Acceptance($membership, $outcome);
    }
}
