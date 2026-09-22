<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\ReinstateMembership;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Notifications\InvitationNotification;

beforeEach(fn () => Date::use(CarbonImmutable::class));

afterEach(fn () => Date::useDefault());

it('runs the full lifecycle when the host uses immutable dates', function () {
    Notification::fake();
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/{$token}");

    $owner = host_user('owner');
    $workspace = host_workspace('Acme', $owner);
    $alice = host_user('alice');

    $issued = issue($owner, $workspace, $alice->email);

    expect(now())->toBeInstanceOf(CarbonImmutable::class)
        ->and($issued->invitation->fresh()->expires_at)->toBeInstanceOf(CarbonImmutable::class);

    $this->travel(2)->minutes();
    $resent = ResendInvitation::run($owner, $issued->invitation);

    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->expiresAt instanceof CarbonImmutable);

    $membership = AcceptInvitation::run($alice, $resent->token)->membership;
    SuspendMembership::run($owner, $membership);
    ReinstateMembership::run($owner, $membership);

    confirm_recent_authentication();
    TransferOwnership::run($owner, $membership);
    RemoveMembership::run($alice, $owner->membershipIn($workspace));
    RevokeInvitation::run($alice, issue($alice, $workspace, 'later@example.test')->invitation);

    expect($membership->fresh()->joined_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($workspace->ownership()->first()->transferred_at)->toBeInstanceOf(CarbonImmutable::class);
});
