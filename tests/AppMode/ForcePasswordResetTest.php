<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Actions\ForcePasswordReset;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\PasswordResetForced;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ForcedPasswordReset;

function app_reset_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

beforeEach(function () {
    Notification::fake();
    config()->set('session.driver', 'database');

    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
    $this->presenter->forceFill([
        'password' => Hash::make('old-secret'),
        'remember_token' => 'remember-me',
        'two_factor_secret' => 'secret',
    ])->save();
});

it('replaces the password, ends sessions and emails a reset link to a user', function () {
    DB::table('sessions')->insert(['id' => 'p1', 'user_id' => $this->presenter->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'a1', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);

    $result = ForcePasswordReset::run($this->admin, $this->presenter);

    $fresh = $this->presenter->fresh();

    expect($result)->toBeInstanceOf(ForcedPasswordReset::class)
        ->and($result->membership)->toBeNull()
        ->and($result->twoFactorReset)->toBeFalse()
        ->and($result->sessionsEnded)->toBe(1)
        ->and(Hash::check('old-secret', $fresh->password))->toBeFalse()
        ->and($fresh->remember_token)->not->toBe('remember-me')
        ->and($fresh->two_factor_secret)->toBe('secret')
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['a1'])
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);

    Notification::assertSentTo($this->presenter, ResetPassword::class);
});

it('clears two-factor only when asked', function () {
    ForcePasswordReset::run($this->admin, $this->presenter, resetTwoFactor: true);

    expect($this->presenter->fresh()->two_factor_secret)->toBeNull();
});

it('dispatches PasswordResetForced without a workspace or membership', function () {
    Event::fake([PasswordResetForced::class]);

    ForcePasswordReset::run($this->admin, $this->presenter, resetTwoFactor: true);

    Event::assertDispatched(PasswordResetForced::class, fn (PasswordResetForced $e) => $e->workspaceId === null
        && $e->membershipId === null
        && $e->userId === $this->presenter->id
        && $e->actorId === $this->admin->id
        && $e->twoFactorReset === true);
});

it('applies the app-wide management rules: not a manager, self, rank, deactivated', function () {
    $other = join_app($this->owner, 'other', ['admin']);

    expect(fn () => ForcePasswordReset::run($this->presenter, $this->admin))->toThrow(app_reset_denied('not_manager'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $this->admin))->toThrow(app_reset_denied('self'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $other))->toThrow(app_reset_denied('rank'))
        ->and(fn () => ForcePasswordReset::run($this->admin, $this->owner))->toThrow(app_reset_denied('rank'));

    DeactivateUser::run($this->admin, $this->presenter);

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenter))->toThrow(app_reset_denied('deactivated'));

    Notification::assertNothingSent();
});

it('requires recent authentication only when the host configures a window', function () {
    config()->set('concierge.recent_authentication_seconds', 300);
    confirm_recent_authentication(false);

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenter))->toThrow(app_reset_denied('recent_auth_required'))
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue();

    confirm_recent_authentication();
    expect(ForcePasswordReset::run($this->admin, $this->presenter))->toBeInstanceOf(ForcedPasswordReset::class);
});

it('lets the host AccountGuard shield an account from a forced reset', function () {
    app()->instance(AccountGuard::class, new class implements AccountGuard
    {
        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return $operation === AccountGuard::FORCE_PASSWORD_RESET
                ? Response::deny('That account is protected.', 'protected')
                : Response::allow();
        }
    });

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenter))->toThrow(app_reset_denied('protected'))
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue();
});

it('rolls everything back when two-factor columns are missing', function () {
    config()->set('concierge.forced_password_reset.two_factor_columns', ['nonexistent_column']);

    expect(fn () => ForcePasswordReset::run($this->admin, $this->presenter, resetTwoFactor: true))->toThrow(UnsupportedConfiguration::class)
        ->and(Hash::check('old-secret', $this->presenter->fresh()->password))->toBeTrue()
        ->and($this->presenter->fresh()->remember_token)->toBe('remember-me');

    Notification::assertNothingSent();
});
