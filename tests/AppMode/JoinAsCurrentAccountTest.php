<?php

use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Exceptions\InvitationRejected;

beforeEach(function () {
    $this->owner = app_owner();
});

it('refuses another account by default', function () {
    $issued = invite_user($this->owner, 'alice@example.test');
    $bob = host_user('bob');

    expect(fn () => AcceptInvitation::run($bob, $issued->token))
        ->toThrow(fn (InvitationRejected $e) => expect($e->state)->toBe(InvitationState::WrongAccount));
});

it('grants a verified other account when match_email is false and records it', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = invite_user($this->owner, 'alice@example.test');
    $bob = host_user('bob');

    expect(InspectInvitation::run($bob, $issued->token)->otherEmail)->toBeTrue()
        ->and(AcceptInvitation::run($bob, $issued->token)->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and($bob->fresh()->hasRole('presenter'))->toBeTrue()
        ->and((int) $issued->invitation->fresh()->accepted_by_id)->toBe($bob->id)
        ->and($issued->invitation->fresh()->accepted_with_other_email)->toBeTrue()
        ->and(AcceptInvitation::run($bob, $issued->token)->outcome)->toBe(AcceptanceOutcome::AlreadyMember);
});

it('still refuses an unverified other account', function () {
    config(['concierge.invitations.match_email' => false]);

    $issued = invite_user($this->owner, 'alice@example.test');
    $bob = host_user('bob', verified: false);

    expect(fn () => AcceptInvitation::run($bob, $issued->token))
        ->toThrow(fn (InvitationRejected $e) => expect($e->state)->toBe(InvitationState::Unverified));
});
