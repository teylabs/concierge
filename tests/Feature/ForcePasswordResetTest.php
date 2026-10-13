<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Actions\ForcePasswordReset;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\PasswordResetForced;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ForcedPasswordReset;
use Workbench\App\Models\User;

function reset_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

function session_row(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);
}

beforeEach(function () {
    Notification::fake();
    config()->set('session.driver', 'database');

    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->adminMembership = join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    $this->presenter = host_user('presenter');
    $this->presenterMembership = join_as($this->workspace, $this->owner, $this->presenter, ['presenter']);
    $this->presenter->forceFill(['password' => Hash::make('old-secret'), 'remember_token' => 'remember-me'])->save();
});

it('applies the workspace management rules: not a manager, self, rank, owner, other workspace', function () {
    $ownerMembership = app(Concierge::class)->ownerOf($this->workspace);
    $second = join_as($this->workspace, $this->owner, host_user('second-admin'), ['admin']);
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    $foreign = join_as($other, $otherOwner, host_user('foreigner'));

    expect(fn () => ForcePasswordReset::run($this->presenter, $this->adminMembership))->toThrow(reset_denied('not_manager'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $this->adminMembership))->toThrow(reset_denied('self'))
        ->and(fn () => ForcePasswordReset::run($this->owner, $ownerMembership))->toThrow(reset_denied('self'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $second))->toThrow(reset_denied('rank'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $ownerMembership))->toThrow(reset_denied('owner_protected'))
        ->and(fn () => ForcePasswordReset::run($this->owner, $foreign))->toThrow(reset_denied('not_member'))
        ->and(fn () => ForcePasswordReset::run(host_user('stranger'), $this->presenterMembership))->toThrow(reset_denied('not_member'))
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue()
        ->and($this->presenter->fresh()->remember_token)->toBe('remember-me');

    Notification::assertNothingSent();
});

it('refuses a removed membership', function () {
    RemoveMembership::run($this->owner, $this->presenterMembership);

    expect(fn () => ForcePasswordReset::run($this->owner, $this->presenterMembership))->toThrow(reset_denied('not_member'));
});

it('requires recent authentication only when the host configures a window', function () {
    confirm_recent_authentication(false);
    ForcePasswordReset::run($this->admin, $this->presenterMembership);

    config()->set('concierge.recent_authentication_seconds', 300);

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenterMembership))->toThrow(reset_denied('recent_auth_required'));

    confirm_recent_authentication();
    expect(ForcePasswordReset::run($this->admin, $this->presenterMembership))->toBeInstanceOf(ForcedPasswordReset::class);
});

it('lets the host AccountGuard veto an otherwise permitted reset', function () {
    app()->instance(AccountGuard::class, new class implements AccountGuard
    {
        public ?string $operation = null;

        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            $this->operation = $operation;

            return $target->conciergeEmail() === 'presenter@example.test'
                ? Response::deny('That account is protected.', 'protected')
                : Response::allow();
        }
    });

    expect(fn () => ForcePasswordReset::run($this->owner, $this->presenterMembership))->toThrow(reset_denied('protected'))
        ->and(app(AccountGuard::class)->operation)->toBe(AccountGuard::FORCE_PASSWORD_RESET)
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue();

    ForcePasswordReset::run($this->owner, $this->adminMembership);
    Notification::assertSentTo($this->admin, ResetPassword::class);
});

it('makes the old password useless and cycles the remember token', function () {
    expect(Auth::attempt(['email' => 'presenter@example.test', 'password' => 'old-secret']))->toBeTrue();
    Auth::logout();

    ForcePasswordReset::run($this->admin, $this->presenterMembership);

    $fresh = $this->presenter->fresh();

    expect(Auth::attempt(['email' => 'presenter@example.test', 'password' => 'old-secret']))->toBeFalse()
        ->and(Hash::check('old-secret', $fresh->password))->toBeFalse()
        ->and($fresh->password)->not->toBe($this->presenter->password)
        ->and(Hash::isHashed($fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe('remember-me')
        ->and(strlen($fresh->remember_token))->toBe(60);
});

it('ends every database session of the target and nobody else', function () {
    session_row($this->presenter, 'p1');
    session_row($this->presenter, 'p2');
    session_row($this->admin, 'a1');

    $result = ForcePasswordReset::run($this->admin, $this->presenterMembership);

    expect($result->sessionsEnded)->toBe(2)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['a1']);
});

it('reports null sessions for a driver that cannot list a user sessions', function () {
    config()->set('session.driver', 'file');
    session_row($this->presenter, 'p1');

    $result = ForcePasswordReset::run($this->admin, $this->presenterMembership);

    expect($result->sessionsEnded)->toBeNull()
        ->and(DB::table('sessions')->count())->toBe(1)
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeFalse();
});

it('sends a reset link through the password broker', function () {
    ForcePasswordReset::run($this->admin, $this->presenterMembership);

    $token = DB::table('password_reset_tokens')->where('email', 'presenter@example.test')->value('token');

    expect($token)->not->toBeNull();
    Notification::assertSentTo($this->presenter, ResetPassword::class, fn (ResetPassword $n) => Hash::check($n->token, $token));
    Notification::assertNotSentTo($this->admin, ResetPassword::class);
});

it('resets two-factor only when asked', function () {
    $this->presenter->forceFill([
        'two_factor_secret' => 'secret',
        'two_factor_recovery_codes' => 'codes',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $kept = ForcePasswordReset::run($this->admin, $this->presenterMembership);

    expect($kept->twoFactorReset)->toBeFalse()
        ->and($this->presenter->fresh()->two_factor_secret)->toBe('secret');

    $reset = ForcePasswordReset::run($this->admin, $this->presenterMembership, resetTwoFactor: true);
    $fresh = $this->presenter->fresh();

    expect($reset->twoFactorReset)->toBeTrue()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

it('refuses a two-factor reset when the app has no two-factor columns', function () {
    config()->set('concierge.forced_password_reset.two_factor_columns', ['missing_column']);

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenterMembership, resetTwoFactor: true))
        ->toThrow(UnsupportedConfiguration::class, 'set concierge.forced_password_reset.two_factor_columns to the user table\'s two-factor columns, or pass resetTwoFactor: false.')
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue();

    Notification::assertNothingSent();
});

it('rolls back the password, remember token and sessions when the broker cannot create a token', function () {
    session_row($this->presenter, 'p1');
    Event::fake([PasswordResetForced::class]);
    app()->instance(PasswordBrokerFactory::class, new class implements PasswordBrokerFactory
    {
        public function broker($name = null)
        {
            // A contract-only broker: it cannot create a token for a given user.
            return new class implements PasswordBroker
            {
                public function sendResetLink(array $credentials, ?Closure $callback = null)
                {
                    return PasswordBroker::RESET_LINK_SENT;
                }

                public function reset(array $credentials, Closure $callback)
                {
                    return PasswordBroker::PASSWORD_RESET;
                }
            };
        }
    });

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenterMembership))
        ->toThrow(UnsupportedConfiguration::class, 'set concierge.forced_password_reset.broker to an auth.passwords broker backed by Laravel\'s Illuminate\\Auth\\Passwords\\PasswordBroker');

    $fresh = $this->presenter->fresh();

    expect(Hash::check('old-secret', $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->toBe('remember-me')
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['p1'])
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);

    Notification::assertNothingSent();
    Event::assertNotDispatched(PasswordResetForced::class);
});

it('dispatches PasswordResetForced after the commit', function () {
    Event::fake([PasswordResetForced::class]);
    session_row($this->presenter, 'p1');

    ForcePasswordReset::run($this->admin, $this->presenterMembership, resetTwoFactor: true);

    Event::assertDispatched(PasswordResetForced::class, fn (PasswordResetForced $e) => $e->workspaceId === $this->workspace->id
        && $e->membershipId === $this->presenterMembership->id
        && $e->userId === $this->presenter->id
        && $e->actorId === $this->admin->id
        && $e->twoFactorReset === true
        && $e->sessionsEnded === 1);
});
