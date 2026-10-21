<?php

namespace Tey\Concierge\Support;

use Illuminate\Validation\ValidationException;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Models\Account;
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
            ! self::emailAllowed($invitation, $user) => InvitationState::WrongAccount,
            ! $user->conciergeHasVerifiedEmail() => InvitationState::Unverified,
            ! $workspace->conciergeAcceptsMembers() => InvitationState::WorkspaceUnavailable,
            $membership?->status === MembershipStatus::Suspended => InvitationState::Suspended,
            $membership?->status === MembershipStatus::Active => InvitationState::AlreadyMember,
            ! self::inviterStillAuthorized($invitation, $workspace) => InvitationState::AuthorityLapsed,
            ! GrantSnapshot::stillValid($invitation) => InvitationState::GrantsChanged,
            default => null,
        };
    }

    /**
     * The app-wide counterpart: no workspace to be unavailable and no
     * membership to already hold. A deactivated account reads as Suspended.
     */
    public static function evaluateAppWide(Invitation $invitation, Member $user, Account $account): ?InvitationState
    {
        return match (true) {
            $invitation->status === InvitationStatus::Accepted => InvitationState::Accepted,
            $invitation->status === InvitationStatus::Revoked => InvitationState::Revoked,
            $invitation->isExpired() => InvitationState::Expired,
            ! self::emailAllowed($invitation, $user) => InvitationState::WrongAccount,
            ! $user->conciergeHasVerifiedEmail() => InvitationState::Unverified,
            $account->status === AccountStatus::Deactivated => InvitationState::Suspended,
            ! self::inviterStillAuthorizedAppWide($invitation) => InvitationState::AuthorityLapsed,
            ! GrantSnapshot::stillValid($invitation) => InvitationState::GrantsChanged,
            default => null,
        };
    }

    /** An inviter's optional message: trimmed, blank as none, at most 500 characters. */
    public static function message(?string $message): ?string
    {
        $message = $message === null ? null : trim($message);

        if ($message !== null && mb_strlen($message) > 500) {
            throw ValidationException::withMessages(['message' => __('The message may not be longer than 500 characters.')]);
        }

        return $message === '' ? null : $message;
    }

    /** True when the signed-in user's email is not the invited one. */
    public static function otherEmail(Invitation $invitation, Member $user): bool
    {
        return ! hash_equals($invitation->email, self::normalizeEmail($user->conciergeEmail()));
    }

    /**
     * With invitations.match_email false, any signed-in account may accept;
     * its own email must still be verified (checked next).
     */
    private static function emailAllowed(Invitation $invitation, Member $user): bool
    {
        return ! config('concierge.invitations.match_email', true) || ! self::otherEmail($invitation, $user);
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

    private static function inviterStillAuthorizedAppWide(Invitation $invitation): bool
    {
        $inviter = $invitation->inviter;

        return $inviter instanceof Member
            && app(AccountPolicy::class)
                ->authorizeGrant($inviter, new GrantSet($invitation->roleNames()))
                ->allowed();
    }
}
