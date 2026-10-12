<?php

use Illuminate\Testing\TestResponse;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Registration\Registration;
use Workbench\App\Models\User;

const REMEMBERED_INVALID = 'That invitation link is not valid. Use the link in your invitation, or ask to be invited again.';

function remembered_register(array $overrides = []): TestResponse
{
    return test()->post('/register', $overrides + [
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);

    config()->set('concierge.registration.domains', 'acme.com');
});

// The invitee's email is never on the allowed domain, so both modes need the token.
dataset('modes', ['invitation', 'domains']);

it('remembers the token from the register link and registers through the unchanged form', function (string $mode) {
    config()->set('concierge.registration.mode', $mode);
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}")->assertOk()->assertSee('register form');
    expect(Registration::remembered())->toBe($issued->token);

    remembered_register()->assertRedirect('/home');

    $this->assertAuthenticatedAs($alice = User::query()->where('email', 'alice@example.test')->firstOrFail());
    expect(Registration::remembered())->toBeNull();

    $alice->forceFill(['email_verified_at' => now()])->save();
    expect(AcceptInvitation::run($alice, $issued->token)->membership->isActive())->toBeTrue();
})->with('modes');

it('registers a tenant invitee through the unchanged form', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = InviteTenant::run(host_user('platform'), 'founder@startup.test');

    $this->get("/register?invitation={$issued->token}")->assertOk();
    remembered_register(['email' => 'founder@startup.test'])->assertRedirect('/home');

    $this->assertAuthenticated();
});

it('refuses without a link or a token', function (string $mode) {
    config()->set('concierge.registration.mode', $mode);

    $this->get('/register')->assertOk();
    expect(Registration::remembered())->toBeNull();

    remembered_register()->assertSessionHasErrors('email');
    $this->assertGuest();
})->with('modes');

it('ignores a blank token on the link', function () {
    config()->set('concierge.registration.mode', 'invitation');

    $this->get('/register?invitation=')->assertOk();
    $this->get('/register?invitation[]=x')->assertOk();

    expect(Registration::remembered())->toBeNull();
});

it('still refuses forged, expired, revoked, accepted and other-email tokens, whichever way they arrived', function (string $mode, string $via) {
    config()->set('concierge.registration.mode', $mode);

    $expiring = issue($this->owner, $this->workspace, 'bob@example.test');
    $revoked = issue($this->owner, $this->workspace, 'alice@example.test');
    RevokeInvitation::run($this->owner, $revoked->invitation);
    $this->travel(8)->days();
    $other = issue($this->owner, $this->workspace, 'alice@example.test');
    $accepted = issue($this->owner, $this->workspace, 'carol@example.test');
    AcceptInvitation::run(host_user('carol'), $accepted->token);

    $cases = [
        [$other->token, 'mallory@example.test', 'Register with the email address your invitation was sent to.'],
        ['forged', 'alice@example.test', REMEMBERED_INVALID],
        [$revoked->token, 'alice@example.test', 'Your invitation was revoked. Ask to be invited again.'],
        [$accepted->token, 'carol@example.test', REMEMBERED_INVALID],
        [$expiring->token, 'bob@example.test', 'Your invitation has expired. Ask to be invited again.'],
    ];

    foreach ($cases as [$token, $email, $message]) {
        $this->flushSession();

        if ($via === 'link') {
            $this->get('/register?invitation='.urlencode($token));
            $response = remembered_register(['email' => $email]);
        } else {
            $response = remembered_register(['email' => $email, 'invitation' => $token]);
        }

        $response->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();
    }

    expect(User::query()->count())->toBe(2);
})->with('modes')->with(['link', 'form']);

it('lets a token in the form win over the remembered one, even when it is the worse of the two', function (string $mode) {
    config()->set('concierge.registration.mode', $mode);
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}");
    remembered_register(['invitation' => 'forged'])->assertSessionHasErrors(['email' => REMEMBERED_INVALID]);
    $this->assertGuest();

    $this->flushSession();
    $this->get('/register?invitation=forged');
    remembered_register(['invitation' => $issued->token])->assertRedirect('/home');
})->with('modes');

it('lets a token in the request query win over the remembered one', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}");
    $this->post('/register?invitation=forged', [
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertSessionHasErrors(['email' => REMEMBERED_INVALID]);
});

it('keeps the remembered token after a refusal and clears it after registering', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}");
    remembered_register(['email' => 'mallory@example.test'])->assertSessionHasErrors('email');
    expect(Registration::remembered())->toBe($issued->token);

    remembered_register()->assertRedirect('/home');
    expect(session()->has(Registration::REMEMBERED_TOKEN))->toBeFalse();
});

it('does not carry the token to another session', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}");
    $this->flushSession();

    remembered_register()->assertSessionHasErrors(['email' => 'Registration is by invitation. Use the link in your invitation.']);
});

it('does not remember the token when registration is closed', function () {
    config()->set('concierge.registration.mode', 'closed');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}")->assertNotFound();
    expect(Registration::remembered())->toBeNull();
});
