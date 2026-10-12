<?php

use Illuminate\Testing\TestResponse;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Registration\Registration;
use Workbench\App\Models\User;

function app_wide_register(array $overrides = []): TestResponse
{
    return test()->post('/register', $overrides + [
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);
}

beforeEach(function () {
    $this->owner = app_owner();

    config()->set('concierge.registration.domains', 'acme.com');
});

dataset('modes', ['invitation', 'domains']);

it('registers an app-wide invitee through the unchanged form', function (string $mode) {
    config()->set('concierge.registration.mode', $mode);
    $issued = invite_user($this->owner, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}")->assertOk()->assertSee('register form');
    app_wide_register()->assertRedirect('/home');

    $this->assertAuthenticatedAs($alice = User::query()->where('email', 'alice@example.test')->firstOrFail());
    expect(Registration::remembered())->toBeNull();

    $alice->forceFill(['email_verified_at' => now()])->save();
    AcceptInvitation::run($alice, $issued->token);
    expect(app_roles($alice))->toBe(['presenter']);
})->with('modes');

it('still refuses forged, expired, revoked, accepted and other-email tokens, whichever way they arrived', function (string $mode, string $via) {
    config()->set('concierge.registration.mode', $mode);

    $expiring = invite_user($this->owner, 'bob@example.test');
    $revoked = invite_user($this->owner, 'alice@example.test');
    RevokeInvitation::run($this->owner, $revoked->invitation);
    $this->travel(8)->days();
    $other = invite_user($this->owner, 'alice@example.test');
    $accepted = invite_user($this->owner, 'carol@example.test');
    AcceptInvitation::run(host_user('carol'), $accepted->token);

    $invalid = 'That invitation link is not valid. Use the link in your invitation, or ask to be invited again.';
    $cases = [
        [$other->token, 'mallory@example.test', 'Register with the email address your invitation was sent to.'],
        ['forged', 'alice@example.test', $invalid],
        [$revoked->token, 'alice@example.test', 'Your invitation was revoked. Ask to be invited again.'],
        [$accepted->token, 'carol@example.test', $invalid],
        [$expiring->token, 'bob@example.test', 'Your invitation has expired. Ask to be invited again.'],
    ];

    foreach ($cases as [$token, $email, $message]) {
        $this->flushSession();

        if ($via === 'link') {
            $this->get('/register?invitation='.urlencode($token));
            $response = app_wide_register(['email' => $email]);
        } else {
            $response = app_wide_register(['email' => $email, 'invitation' => $token]);
        }

        $response->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();
    }

    expect(User::query()->count())->toBe(2);
})->with('modes')->with(['link', 'form']);

it('lets a token in the form win over the remembered one', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = invite_user($this->owner, 'alice@example.test');

    $this->get("/register?invitation={$issued->token}");
    app_wide_register(['invitation' => 'forged'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->flushSession();
    $this->get('/register?invitation=forged');
    app_wide_register(['invitation' => $issued->token])->assertRedirect('/home');
});
