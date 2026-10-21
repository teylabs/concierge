<?php

use Illuminate\Http\Request;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Support\MembersIndex;

function join_rejected_as(InvitationState $state): Closure
{
    return fn (InvitationRejected $e) => expect($e->state)->toBe($state);
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
});

it('refuses another account by default', function () {
    expect(config('concierge.invitations.match_email'))->toBeTrue();

    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    $bob = host_user('bob');

    expect(InspectInvitation::run($bob, $issued->token)->state)->toBe(InvitationState::WrongAccount)
        ->and(fn () => AcceptInvitation::run($bob, $issued->token))->toThrow(join_rejected_as(InvitationState::WrongAccount))
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending);
});

it('lets a verified other account join when match_email is false and records it', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    $bob = host_user('bob');

    $inspection = InspectInvitation::run($bob, $issued->token);

    expect($inspection->state)->toBe(InvitationState::Ready)
        ->and($inspection->otherEmail)->toBeTrue();

    $acceptance = AcceptInvitation::run($bob, $issued->token);
    $invitation = $issued->invitation->fresh();

    expect($acceptance->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and((int) $acceptance->membership->user_id)->toBe($bob->id)
        ->and($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and((int) $invitation->accepted_by_id)->toBe($bob->id)
        ->and($invitation->accepted_with_other_email)->toBeTrue()
        ->and(AcceptInvitation::run($bob, $issued->token)->outcome)->toBe(AcceptanceOutcome::AlreadyMember);
});

it('records a matching email as not other when match_email is false', function () {
    config(['concierge.invitations.match_email' => false]);

    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    expect(InspectInvitation::run($alice, $issued->token)->otherEmail)->toBeFalse();

    AcceptInvitation::run($alice, $issued->token);

    expect($issued->invitation->fresh()->accepted_with_other_email)->toBeFalse();
});

it('still refuses an unverified other account and every other check', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    $bob = host_user('bob', verified: false);

    expect(fn () => AcceptInvitation::run($bob, $issued->token))->toThrow(join_rejected_as(InvitationState::Unverified));

    $carol = host_user('carol');
    RevokeInvitation::run($this->owner, $issued->invitation);

    expect(fn () => AcceptInvitation::run($carol, $issued->token))->toThrow(join_rejected_as(InvitationState::Revoked));

    $expired = issue($this->owner, $this->workspace, 'dave@example.test');
    $this->travel(8)->days();

    expect(fn () => AcceptInvitation::run($carol, $expired->token))->toThrow(join_rejected_as(InvitationState::Expired));
});

it('consumes the invitation for an other account that is already a member', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(AcceptInvitation::run($this->owner, $issued->token)->outcome)->toBe(AcceptanceOutcome::AlreadyMember)
        ->and($issued->invitation->fresh()->accepted_with_other_email)->toBeTrue();
});

it('shows who accepted with another email on the member row', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    $bob = host_user('bob');
    AcceptInvitation::run($bob, $issued->token);

    $rows = collect(app(MembersIndex::class)->props($this->owner, $this->workspace, Request::create('/members'))->toArray()['members'])
        ->keyBy(fn (array $row) => $row['user']['name']);

    expect($rows['Bob']['invitedEmail'])->toBe('alice@example.test')
        ->and($rows['Bob']['acceptedWithOtherEmail'])->toBeTrue()
        ->and($rows['Owner']['invitedEmail'])->toBeNull()
        ->and($rows['Owner']['acceptedWithOtherEmail'])->toBeFalse();
});
