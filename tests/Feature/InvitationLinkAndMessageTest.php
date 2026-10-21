<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\CopyInvitationLink;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Events\InvitationLinkCopied;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Notifications\InvitationNotification;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\MembersIndex;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    $this->presenter = host_user('presenter');
    join_as($this->workspace, $this->owner, $this->presenter, ['presenter']);
});

function link_index(object $actor, object $workspace): array
{
    return app(MembersIndex::class)
        ->urlsUsing(fn (string $action, $subject) => '/'.$action.($subject ? '/'.($subject->public_id ?? $subject->getKey()) : ''))
        ->props($actor, $workspace, Request::create('/members', 'GET', ['tab' => 'invitations']))
        ->toArray();
}

it('copies a link by rotating the token: the old link dies and nothing is emailed', function () {
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, string $token) => "/invitations/{$token}");
    $issued = issue($this->owner, $this->workspace, 'cy@example.test');
    Queue::fake();
    Event::fake([InvitationLinkCopied::class]);

    $copied = CopyInvitationLink::run($this->owner, $issued->invitation);

    expect($copied->token)->not->toBe($issued->token)
        ->and($copied->invitation->fresh()->generation)->toBe(2);
    Queue::assertNotPushed(DeliverInvitation::class);
    Event::assertDispatched(InvitationLinkCopied::class, fn ($event) => $event->invitationId === $issued->invitation->id && $event->generation === 2);

    $cy = host_user('cy');

    expect(fn () => AcceptInvitation::run($cy, $issued->token))->toThrow(InvitationRejected::class)
        ->and(AcceptInvitation::run($cy, $copied->token)->membership->isActive())->toBeTrue();
});

it('lets only inviters copy a link, and only for a pending invitation', function () {
    $issued = issue($this->owner, $this->workspace, 'cy@example.test', ['admin']);

    expect(fn () => CopyInvitationLink::run($this->presenter, $issued->invitation))->toThrow(AuthorizationException::class)
        ->and(fn () => CopyInvitationLink::run($this->admin, $issued->invitation))->toThrow(AuthorizationException::class);

    $accepted = issue($this->owner, $this->workspace, 'dee@example.test');
    AcceptInvitation::run(host_user('dee'), $accepted->token);

    expect(fn () => CopyInvitationLink::run($this->owner, $accepted->invitation))->toThrow(ValidationException::class);
});

it('offers copy link on pending invitations to inviters only, warning that the previous link stops working', function () {
    issue($this->owner, $this->workspace, 'cy@example.test', ['presenter']);
    issue($this->owner, $this->workspace, 'dee@example.test', ['admin']);

    $asOwner = link_index($this->owner, $this->workspace);
    $copy = collect($asOwner['invitations'][0]['actions'])->firstWhere('key', 'copy_link');

    expect(array_column($asOwner['invitations'][0]['actions'], 'key'))->toBe(['resend', 'copy_link', 'revoke'])
        ->and($copy)->toMatchArray(['method' => 'post', 'destructive' => false])
        ->and($copy['confirm']['body'])->toBe('The previous link will stop working. No email is sent.')
        ->and(array_column(link_index($this->admin, $this->workspace)['invitations'][1]['actions'], 'key'))->toBe(['revoke'])
        ->and(array_column(link_index($this->admin, $this->workspace)['invitations'][0]['actions'], 'key'))->toBe(['resend', 'copy_link', 'revoke']);
});

it('confirms suspend and reinstate', function () {
    $membership = $this->presenter->membershipIn($this->workspace);
    $rows = fn () => collect(app(MembersIndex::class)
        ->urlsUsing(fn (string $action, $subject) => "/{$action}")
        ->props($this->owner, $this->workspace, Request::create('/members'))->toArray()['members']);

    $suspend = collect($rows()->firstWhere('user.name', 'Presenter')['actions'])->firstWhere('key', 'suspend');
    expect($suspend['confirm'])->toBe(['title' => 'Suspend Presenter?', 'body' => 'They lose access until reinstated. Their roles are kept.', 'confirmLabel' => 'Suspend member']);

    SuspendMembership::run($this->owner, $membership);

    $reinstate = collect($rows()->firstWhere('user.name', 'Presenter')['actions'])->firstWhere('key', 'reinstate');
    expect($reinstate['confirm'])->toBe(['title' => 'Reinstate Presenter?', 'body' => 'They get their access and roles back.', 'confirmLabel' => 'Reinstate member']);
});

it('keeps an optional message on invite and resend, on the rows and in delivery', function () {
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, string $token) => "/invitations/{$token}?m=".urlencode((string) $invitation->message));
    Notification::fake();

    $issued = InviteMember::run($this->owner, $this->workspace, 'cy@example.test', new GrantSet(['presenter']), 'Welcome aboard.');

    expect($issued->invitation->fresh()->message)->toBe('Welcome aboard.')
        ->and(link_index($this->owner, $this->workspace)['invitations'][0]['message'])->toBe('Welcome aboard.');

    Notification::assertSentOnDemand(InvitationNotification::class, fn ($notification) => $notification->message === 'Welcome aboard.' && str_contains($notification->url, 'Welcome'));

    $this->travel(2)->minutes();
    $resent = ResendInvitation::run($this->owner, $issued->invitation, 'Second try.');
    expect($resent->invitation->fresh()->message)->toBe('Second try.');

    $this->travel(2)->minutes();
    expect(ResendInvitation::run($this->owner, $issued->invitation)->invitation->fresh()->message)->toBe('Second try.');
});

it('refuses a message over 500 characters and stores a blank one as none', function () {
    expect(fn () => InviteMember::run($this->owner, $this->workspace, 'cy@example.test', new GrantSet(['presenter']), str_repeat('a', 501)))
        ->toThrow(ValidationException::class, 'The message may not be longer than 500 characters.');

    expect(InviteMember::run($this->owner, $this->workspace, 'cy@example.test', new GrantSet(['presenter']), '   ')->invitation->fresh()->message)->toBeNull()
        ->and(InviteMember::run($this->owner, $this->workspace, 'dee@example.test', new GrantSet(['presenter']), str_repeat('a', 500))->invitation->fresh()->message)->toHaveLength(500);
});
