<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Support\InvitationHandoff;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->alice = host_user('alice');
    $this->mallory = host_user('mallory');

    foreach ([$this->alice, $this->mallory] as $user) {
        $user->forceFill(['password' => Hash::make('correct-horse-battery')])->save();
    }

    $this->issued = issue($this->owner, $this->workspace, 'alice@example.test');

    // The host's acceptance routes: the page, and the Switch account button.
    Route::middleware('web')->get('/invitations/{token}', fn (Request $request, string $token) => [
        'state' => InspectInvitation::run($request->user(), $token)->state->value,
    ]);
    Route::middleware('web')->post('/invitations/{token}/switch', fn (Request $request, string $token) => InvitationHandoff::switchAccount($request, $token, "/invitations/{$token}", '/login'));
    Route::middleware('web')->get('/invitations/{token}/sign-in', function (Request $request, string $token) {
        InvitationHandoff::start($token, "/invitations/{$token}");

        return redirect('/login');
    });
});

function handoff_from(TestResponse $response): string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === InvitationHandoff::COOKIE);

    expect($cookie)->not->toBeNull();

    return (string) $cookie->getValue();
}

function sign_in_as(string $email): TestResponse
{
    return test()->post('/login', ['email' => $email, 'password' => 'correct-horse-battery']);
}

it('signs the wrong account out and keeps the invitation through sign-in', function () {
    $this->actingAs($this->mallory)->get("/invitations/{$this->issued->token}")->assertJson(['state' => 'wrong_account']);

    $switched = $this->post("/invitations/{$this->issued->token}/switch")->assertRedirect('/login');
    $this->assertGuest();

    $handoff = handoff_from($switched);
    $this->flushSession();

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, $handoff);
    sign_in_as('alice@example.test')->assertRedirect("/invitations/{$this->issued->token}");

    $this->assertAuthenticatedAs($this->alice);
    $this->get("/invitations/{$this->issued->token}")->assertJson(['state' => 'ready']);
});

it('returns a signed-out user with an account to the invitation after sign-in', function () {
    $response = $this->get("/invitations/{$this->issued->token}/sign-in")->assertRedirect('/login');

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, handoff_from($response));
    sign_in_as('alice@example.test')->assertRedirect("/invitations/{$this->issued->token}");
});

it('returns to the invitation after registering, and the handoff counts as the register link', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = issue($this->owner, $this->workspace, 'newcomer@example.test');

    $switched = $this->actingAs($this->mallory)->post("/invitations/{$issued->token}/switch");
    $this->flushSession();

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, handoff_from($switched));
    $this->post('/register', [
        'name' => 'Newcomer',
        'email' => 'newcomer@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect("/invitations/{$issued->token}");

    $this->assertAuthenticated();
});

it('shows an invitation revoked or expired after the switch as such', function (string $outcome) {
    $switched = $this->actingAs($this->mallory)->post("/invitations/{$this->issued->token}/switch");
    $this->flushSession();

    if ($outcome === 'revoked') {
        RevokeInvitation::run($this->owner, $this->issued->invitation);
    } else {
        $this->issued->invitation->forceFill(['expires_at' => now()->addMinutes(5)])->save();
        $this->travel(10)->minutes();
    }

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, handoff_from($switched));
    sign_in_as('alice@example.test')->assertRedirect("/invitations/{$this->issued->token}");

    $this->get("/invitations/{$this->issued->token}")->assertJson(['state' => $outcome]);
})->with(['revoked', 'expired']);

it('lets the handoff lapse after its own short lifetime', function () {
    $switched = $this->actingAs($this->mallory)->post("/invitations/{$this->issued->token}/switch");
    $this->flushSession();
    $this->travel(InvitationHandoff::MINUTES + 1)->minutes();

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, handoff_from($switched));
    sign_in_as('alice@example.test')->assertRedirect('/home');
});

it('ignores a tampered, forged or stale handoff', function () {
    $switched = $this->actingAs($this->mallory)->post("/invitations/{$this->issued->token}/switch");
    $this->flushSession();
    $handoff = handoff_from($switched);

    $stale = Crypt::encryptString(json_encode(['token' => $this->issued->token, 'url' => '/invitations/x', 'expires' => now()->subMinute()->getTimestamp()]));
    $offsite = Crypt::encryptString(json_encode(['token' => $this->issued->token, 'url' => 'https://evil.test/', 'expires' => now()->addMinute()->getTimestamp()]));

    foreach ([substr($handoff, 0, -4).'abcd', 'forged', $stale, $offsite] as $value) {
        $this->withUnencryptedCookie(InvitationHandoff::COOKIE, $value);
        sign_in_as('alice@example.test')->assertRedirect('/home');
        $this->post('/logout');
        $this->flushSession();
    }
});

it('is used once: the next sign-in goes home', function () {
    $switched = $this->actingAs($this->mallory)->post("/invitations/{$this->issued->token}/switch");
    $this->flushSession();

    $this->withUnencryptedCookie(InvitationHandoff::COOKIE, handoff_from($switched));
    $first = sign_in_as('alice@example.test')->assertRedirect("/invitations/{$this->issued->token}");

    $forgotten = collect($first->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === InvitationHandoff::COOKIE);
    expect($forgotten?->isCleared())->toBeTrue();
});
