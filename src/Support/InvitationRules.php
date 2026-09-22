<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;

/**
 * Shared by read-only inspection and locked acceptance. Returns null when the
 * invitation may be accepted, AlreadyMember when it is consumed without
 * grants, or the rejecting state.
 */
final class InvitationRules
{
    public static function evaluate(Invitation $invitation, Member $user, ?Membership $membership, Workspace $workspace): ?InvitationState
    {
        return match (true) {
            $invitation->status === InvitationStatus::Accepted => InvitationState::Accepted,
            $invitation->status === InvitationStatus::Revoked => InvitationState::Revoked,
            $invitation->isExpired() => InvitationState::Expired,
            ! hash_equals($invitation->email, self::normalizeEmail($user->conciergeEmail())) => InvitationState::WrongAccount,
            ! $user->conciergeHasVerifiedEmail() => InvitationState::Unverified,
            ! $workspace->conciergeAcceptsMembers() => InvitationState::WorkspaceUnavailable,
            $membership?->status === MembershipStatus::Suspended => InvitationState::Suspended,
            $membership?->status === MembershipStatus::Active => InvitationState::AlreadyMember,
            ! self::inviterStillAuthorized($invitation, $workspace) => InvitationState::AuthorityLapsed,
            ! GrantSnapshot::stillValid($invitation) => InvitationState::GrantsChanged,
            default => null,
        };
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function inviterStillAuthorized(Invitation $invitation, Workspace $workspace): bool
    {
        $inviter = $invitation->inviter;

        return $inviter instanceof Member
            && app(GrantPolicy::class)
                ->authorizeGrant($inviter, $workspace, new GrantSet($invitation->roleNames()))
                ->allowed();
    }
}
