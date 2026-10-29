<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Http\InertiaPageResponder;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Notifications\InvitationNotification;
use Tey\Concierge\Support\CurrentWorkspace;
use Tey\Concierge\Support\InvitationHandoff;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->acme = host_workspace('Acme', $this->owner);
    $this->alice = host_user('alice');
    $this->issued = issue($this->owner, $this->acme, 'alice@example.test');
    $this->page = "/invitations/{$this->issued->token}";
});

it('emails invitations by default, linking to the invitation page', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/members/invite', ['email' => 'dave@example.test', 'roles' => ['presenter']])->assertStatus(303);

    $invitation = Invitation::query()->where('email', 'dave@example.test')->firstOrFail();
    $sent = null;

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification, array $channels, object $notifiable) use (&$sent) {
        $sent = $notification->url;

        return $notifiable->routes['mail'] === 'dave@example.test';
    });

    $token = substr((string) $sent, strlen(url('/invitations/')) + 1);

    expect($sent)->toStartWith(url('/invitations').'/')
        ->and(Invitation::hashToken($token))->toBe($invitation->token_hash)
        ->and($invitation->fresh()->send_count)->toBe(1);
});

it('keeps the link from invitationUrlUsing', function () {
    Notification::fake();
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/join/{$token}");

    $issued = issue($this->owner, $this->acme, 'dave@example.test');

    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->url === "https://host.test/join/{$issued->token}");
});

it('asks a signed-out visitor to sign in, and remembers the invitation for afterwards', function () {
    $response = $this->getJson($this->page)->assertOk()->assertHeader(InertiaPageResponder::HEADER, 'Concierge/AcceptInvitation');

    expect($response->json('state'))->toBe('sign_in_required')
        ->and($response->json('email'))->toBe('alice@example.test')
        ->and($response->json('workspace.name'))->toBe('Acme')
        ->and($response->json('metadata.links.accept'))->toBeNull();

    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === InvitationHandoff::COOKIE);
    expect($cookie)->not->toBeNull();

    // Unknown visitors are asked to register; the app's login and register routes are linked when it has them.
    Route::get('/login', fn () => 'login')->name('login');
    Route::get('/register', fn () => 'register')->name('register');
    Route::getRoutes()->refreshNameLookups();

    $stranger = issue($this->owner, $this->acme, 'newcomer@example.test');

    expect($this->getJson("/invitations/{$stranger->token}")->json())->toMatchArray(['state' => 'register_required'])
        ->and($this->getJson("/invitations/{$stranger->token}")->json('metadata.links'))->toMatchArray(['login' => url('/login'), 'register' => url('/register')]);
});

it('accepts for the invited user and makes the workspace current', function () {
    $ready = $this->actingAs($this->alice)->getJson($this->page)->assertOk();

    expect($ready->json('state'))->toBe('ready')
        ->and($ready->json('metadata.links.accept'))->toBe(route('concierge.invitations.accept', $this->issued->token));

    $this->actingAs($this->alice)->post("{$this->page}/accept")->assertStatus(303)->assertRedirect(url('/'));

    expect($this->alice->membershipIn($this->acme)?->status)->toBe(MembershipStatus::Active)
        ->and(session(CurrentWorkspace::SESSION_KEY))->toBe($this->acme->id);

    // A retry changes nothing and still succeeds.
    $this->actingAs($this->alice)->post("{$this->page}/accept")->assertStatus(303);
    expect($this->actingAs($this->alice)->getJson($this->page)->json('state'))->toBe('already_member');
});

it('refuses to accept for a visitor, the wrong account or a revoked invitation', function () {
    $this->postJson("{$this->page}/accept")->assertUnauthorized();

    $mallory = host_user('mallory');
    $wrong = $this->actingAs($mallory)->getJson($this->page);

    expect($wrong->json('state'))->toBe('wrong_account')
        ->and($wrong->json('signedInAs'))->toBe('mallory@example.test')
        ->and($wrong->json('metadata.links.switchAccount'))->toBe(route('concierge.invitations.switch-account', $this->issued->token))
        ->and($wrong->json('metadata.links.cancel'))->toBe(url('/'));

    $this->actingAs($mallory)->postJson("{$this->page}/accept")->assertStatus(409)->assertJson(['state' => 'wrong_account']);
    $this->actingAs($mallory)->post("{$this->page}/accept")->assertRedirect(route('concierge.invitations.show', $this->issued->token));

    RevokeInvitation::run($this->owner, $this->issued->invitation);
    $this->actingAs($this->alice)->postJson("{$this->page}/accept")->assertStatus(409)->assertJson(['state' => 'revoked']);

    expect($this->alice->membershipIn($this->acme))->toBeNull()
        ->and($mallory->membershipIn($this->acme))->toBeNull()
        ->and($this->issued->invitation->fresh()->status)->toBe(InvitationStatus::Revoked);
});

it('signs the wrong account out to switch, keeping the invitation', function () {
    $mallory = host_user('mallory');

    $response = $this->actingAs($mallory)->post("{$this->page}/switch-account")->assertRedirect(url('/'));

    $this->assertGuest();
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === InvitationHandoff::COOKIE))->toBeTrue();
});

it('shows an unknown token as invalid without naming anything', function () {
    $response = $this->getJson('/invitations/not-a-token')->assertOk();

    expect($response->json())->toMatchArray(['state' => 'invalid', 'workspace' => null, 'email' => null, 'roles' => []])
        ->and($response->json('metadata.links.continue'))->toBe(url('/'));
});
