<?php

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterViewResponse;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Registration\Fortify\GatedRegisterView;
use Tey\Concierge\Registration\Fortify\GatedUserCreator;
use Tey\Concierge\Rules\MayRegister;
use Workbench\App\Models\User;

/** The same row as a user Laravel would send a verification email to. */
class VerifyingUser extends User implements MustVerifyEmail
{
    use MustVerifyEmailTrait;

    protected $table = 'users';
}

/** Laravel's own listener, as a host registers it, run for the newly registered account. */
function registered_listener_sends_to(string $email): bool
{
    $user = VerifyingUser::query()->where('email', $email)->firstOrFail();
    (new SendEmailVerificationNotification)->handle(new Registered($user));

    return Notification::sent($user, VerifyEmail::class)->isNotEmpty();
}

function register(array $overrides = []): TestResponse
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

    // Most of these tests exercise the invitation gate.
    config()->set('concierge.registration.mode', 'invitation');
});

it('wraps the host CreateNewUser and register view with no app code', function () {
    expect(app(CreatesNewUsers::class))->toBeInstanceOf(GatedUserCreator::class)
        ->and(app(RegisterViewResponse::class))->toBeInstanceOf(GatedRegisterView::class)
        ->and(app(CreatesNewUsers::class))->toBe(app(CreatesNewUsers::class));
});

it('registers anyone in open mode', function () {
    config()->set('concierge.registration.mode', 'open');

    $this->get('/register')->assertOk()->assertSee('register form');
    register()->assertRedirect('/home');

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'alice@example.test')->exists())->toBeTrue();
});

it('registers anyone by default, so an upgrade keeps registration open', function () {
    config()->set('concierge.registration', (require __DIR__.'/../../config/concierge.php')['registration']);

    register()->assertRedirect('/home');

    $this->assertAuthenticated();
});

it('refuses registration without an invitation in invitation mode, on the email field', function () {
    register()->assertSessionHasErrors(['email' => 'Registration is by invitation. Use the link in your invitation.']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

it('registers the invitee when the request carries a valid token, then they can accept', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    register(['email' => 'Alice@Example.test', 'invitation' => $issued->token])->assertRedirect('/home');

    $this->assertAuthenticatedAs($alice = User::query()->where('email', 'Alice@Example.test')->firstOrFail());

    expect(AcceptInvitation::run($alice, $issued->token)->membership->isActive())->toBeTrue();
});

it('starts an invited registration verified, so Fortify sends no verification email', function () {
    Notification::fake();
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    register(['email' => 'Alice@Example.test', 'invitation' => $issued->token])->assertRedirect('/home');

    expect(User::query()->where('email', 'Alice@Example.test')->firstOrFail()->email_verified_at)->not->toBeNull()
        ->and(registered_listener_sends_to('Alice@Example.test'))->toBeFalse();
});

it('leaves an uninvited registration unverified, so Fortify still sends its verification email', function () {
    config()->set('concierge.registration.mode', 'open');
    Notification::fake();

    register()->assertRedirect('/home');

    expect(User::query()->where('email', 'alice@example.test')->firstOrFail()->email_verified_at)->toBeNull()
        ->and(registered_listener_sends_to('alice@example.test'))->toBeTrue();
});

it('refuses a forged, expired or revoked token, and a token for another email', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    register(['invitation' => 'forged'])->assertSessionHasErrors(['email' => 'That invitation link is not valid. Use the link in your invitation, or ask to be invited again.']);
    register(['email' => 'mallory@example.test', 'invitation' => $issued->token])->assertSessionHasErrors(['email' => 'Register with the email address your invitation was sent to.']);

    RevokeInvitation::run($this->owner, $issued->invitation);
    register(['invitation' => $issued->token])->assertSessionHasErrors(['email' => 'Your invitation was revoked. Ask to be invited again.']);

    $later = issue($this->owner, $this->workspace, 'bob@example.test');
    $this->travel(8)->days();
    register(['email' => 'bob@example.test', 'invitation' => $later->token])->assertSessionHasErrors(['email' => 'Your invitation has expired. Ask to be invited again.']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

it('honours a tenant invitation', function () {
    $issued = InviteTenant::run(host_user('platform'), 'founder@startup.test');

    register(['email' => 'founder@startup.test', 'invitation' => $issued->token])->assertRedirect('/home');

    $this->assertAuthenticated();
});

it('admits allowed domains case-insensitively and invitees from elsewhere in domains mode', function () {
    config()->set('concierge.registration.mode', 'domains');
    config()->set('concierge.registration.domains', 'Acme.com,acme.io');

    register(['email' => 'Jane@ACME.COM'])->assertRedirect('/home');
    auth()->logout();

    register(['email' => 'jane@mail.acme.com'])->assertSessionHasErrors(['email' => 'Registration is limited to approved email domains. Use your work email address, or the link in your invitation.']);
    register(['email' => 'jane@other.test'])->assertSessionHasErrors('email');

    $issued = issue($this->owner, $this->workspace, 'contractor@other.test');
    register(['email' => 'contractor@other.test', 'invitation' => $issued->token])->assertRedirect('/home');

    expect(User::query()->orderBy('id')->pluck('email')->all())->toBe(['owner@example.test', 'Jane@ACME.COM', 'contractor@other.test']);
});

it('closes registration in domains mode with no domains', function () {
    config()->set('concierge.registration.mode', 'domains');
    config()->set('concierge.registration.domains', '');

    register(['email' => 'jane@acme.com'])->assertSessionHasErrors(['email' => 'Registration is closed.']);

    $this->assertGuest();
});

it('makes the register page and the request a 404 in closed mode, even with a valid invitation', function () {
    config()->set('concierge.registration.mode', 'closed');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $this->get('/register')->assertNotFound();
    register(['invitation' => $issued->token])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

it('treats an unknown mode as closed', function () {
    config()->set('concierge.registration.mode', 'anyone');

    $this->get('/register')->assertNotFound();
    register()->assertNotFound();
});

it('leaves the kit own validation in place behind the gate', function () {
    config()->set('concierge.registration.mode', 'open');

    register(['password_confirmation' => 'different'])->assertSessionHasErrors('password')->assertSessionDoesntHaveErrors('email');
    register(['email' => 'owner@example.test'])->assertSessionHasErrors('email');
});

it('can be used by hand in the kit CreateNewUser as the spec shows', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $validator = Validator::make(['email' => 'alice@example.test', 'invitation' => $issued->token], [
        'email' => ['required', 'email', 'unique:users', new MayRegister],
    ]);

    expect($validator->passes())->toBeTrue();
});
