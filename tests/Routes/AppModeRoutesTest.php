<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Notifications\InvitationNotification;

/*
 * App-wide mode serves only the invitation pages for now; the Users pages get
 * their routes in a later release.
 */

beforeEach(function () {
    $this->owner = app_owner();
});

it('registers only the invitation routes in app-wide mode', function () {
    expect(collect(app(Router::class)->getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge.'))->sort()->values()->all())->toBe([
        'concierge.invitations.accept',
        'concierge.invitations.show',
        'concierge.invitations.switch-account',
    ]);

    $this->actingAs($this->owner)->get('/members')->assertNotFound();
});

it('emails an app-wide invitation that the invitee accepts on concierge\'s page', function () {
    Notification::fake();

    $issued = invite_user($this->owner, 'alice@example.test');

    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->url === route('concierge.invitations.show', $issued->token));

    $alice = host_user('alice');
    $page = $this->actingAs($alice)->getJson("/invitations/{$issued->token}")->assertOk();

    expect($page->json('state'))->toBe('ready')
        ->and($page->json('workspace'))->toBeNull()
        ->and($page->json('metadata.appName'))->toBe(config('app.name'));

    $this->actingAs($alice)->post("/invitations/{$issued->token}/accept")->assertStatus(303);

    expect(app_roles($alice))->toBe(['presenter']);
});
