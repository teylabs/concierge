<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\InvitationAccepted;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\Acceptance;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

/**
 * Authoritative acceptance. A rejection throws and writes nothing. An
 * invitation with no workspace_id is app-wide and grants roles to the user's
 * account instead of creating a membership.
 */
class AcceptInvitation
{
    use Runnable;

    public function __construct(protected Concierge $concierge) {}

    public function handle(Member $user, #[SensitiveParameter] string $token): Acceptance
    {
        $hash = Invitation::hashToken($token);

        $invitation = ConciergeModels::invitation()::query()->where('token_hash', $hash)->first(['id', 'workspace_id'])
            ?? throw new InvitationRejected(InvitationState::Invalid);

        if ($invitation->isAppWide()) {
            return $this->acceptAppWide($user, $hash);
        }

        $workspaceId = $invitation->workspace_id;

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
            'accepted_with_other_email' => InvitationRules::otherEmail($invitation, $user),
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

    /**
     * App-wide: the lock root is the user's account row (created here when
     * missing), then the invitation row. The roles are granted directly, with
     * Spatie teams off. Already a member only means this same invitation was
     * accepted by this user before; a user who has an account and roles is
     * never invited (see InviteUser), so there is nothing else to short-cut.
     */
    protected function acceptAppWide(Member $user, string $hash): Acceptance
    {
        return DB::transaction(function () use ($user, $hash) {
            $account = Locks::accounts([(int) $user->getKey()])[(int) $user->getKey()];

            $invitation = ConciergeModels::invitation()::query()
                ->where('token_hash', $hash)
                ->whereNull('workspace_id')
                ->lockForUpdate()
                ->first() ?? throw new InvitationRejected(InvitationState::Invalid);

            if ($invitation->status === InvitationStatus::Accepted
                && (int) $invitation->accepted_by_id === (int) $user->getKey()
                && $account->isActive()) {
                return new Acceptance(null, AcceptanceOutcome::AlreadyMember, $account);
            }

            $state = InvitationRules::evaluateAppWide($invitation, $user, $account);

            if ($state !== null) {
                throw new InvitationRejected($state);
            }

            $account->forceFill([
                'status' => AccountStatus::Active,
                'invitation_id' => $invitation->getKey(),
                'joined_at' => now(),
            ])->save();

            $user->syncRoles(Roles::resolve($invitation->roleNames()));

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted,
                'pending_key' => null,
                'accepted_at' => now(),
                'accepted_by_id' => $user->getKey(),
                'accepted_with_other_email' => InvitationRules::otherEmail($invitation, $user),
            ])->save();

            InvitationAccepted::dispatch(null, $invitation->getKey(), null, (int) $user->getKey(), AcceptanceOutcome::Joined->value);

            return new Acceptance(null, AcceptanceOutcome::Joined, $account);
        });
    }
}
