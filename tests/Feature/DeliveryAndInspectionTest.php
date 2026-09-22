<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Notifications\InvitationNotification;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
});

it('queues encrypted delivery only when the host supplies a URL', function () {
    Bus::fake([DeliverInvitation::class]);

    issue($this->owner, $this->workspace, 'a@example.test');
    Bus::assertNotDispatched(DeliverInvitation::class);

    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/invitations/{$token}");
    $issued = issue($this->owner, $this->workspace, 'b@example.test');

    Bus::assertDispatched(DeliverInvitation::class, fn (DeliverInvitation $job) => $job instanceof ShouldBeEncrypted
        && $job->token === $issued->token
        && $job->generation === 1);
});

it('sends the current link and records transport submission', function () {
    Notification::fake();
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/invitations/{$token}");

    $issued = issue($this->owner, $this->workspace, 'a@example.test');

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n, array $channels, object $notifiable) use ($issued) {
        return $notifiable->routes['mail'] === 'a@example.test'
            && $n->url === "https://host.test/invitations/{$issued->token}";
    });

    expect($issued->invitation->fresh()->send_count)->toBe(1)
        ->and($issued->invitation->fresh()->last_sent_at)->not->toBeNull();
});

it('drops stale deliveries after rotation or revocation', function () {
    Notification::fake();
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/{$token}");
    config()->set('concierge.invitations.deliver', false);
    $issued = issue($this->owner, $this->workspace, 'a@example.test');
    config()->set('concierge.invitations.deliver', true);

    $this->travel(2)->minutes();
    ResendInvitation::run($this->owner, $issued->invitation);
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);

    (new DeliverInvitation($issued->invitation->id, 1, $issued->token))->handle(app(Concierge::class));
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);

    RevokeInvitation::run($this->owner, $issued->invitation);
    $fresh = $issued->invitation->fresh();
    (new DeliverInvitation($fresh->id, $fresh->generation, 'whatever'))->handle(app(Concierge::class));
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);
});

it('inspects states read-only', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);
    $unregistered = issue($this->owner, $this->workspace, 'nobody@example.test');
    $inspect = fn ($user, $token) => InspectInvitation::run($user, $token)->state;

    expect($inspect(null, 'bogus'))->toBe(InvitationState::Invalid)
        ->and($inspect(null, $issued->token))->toBe(InvitationState::SignInRequired)
        ->and($inspect(null, $unregistered->token))->toBe(InvitationState::RegisterRequired)
        ->and($inspect(host_user('mallory'), $issued->token))->toBe(InvitationState::WrongAccount)
        ->and($inspect($alice, $issued->token))->toBe(InvitationState::Ready)
        ->and($issued->invitation->fresh()->isPending())->toBeTrue();

    AcceptInvitation::run($alice, $issued->token);

    expect($inspect($alice, $issued->token))->toBe(InvitationState::AlreadyMember)
        ->and($inspect(host_user('bob'), $issued->token))->toBe(InvitationState::Accepted);

    RevokeInvitation::run($this->owner, $unregistered->invitation);

    expect($inspect(null, $unregistered->token))->toBe(InvitationState::Revoked);
});
