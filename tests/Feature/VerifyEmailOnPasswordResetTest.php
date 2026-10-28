<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

it('verifies an unverified account when its password is reset through the emailed link', function () {
    $alice = host_user('alice', verified: false);
    $token = Password::broker()->createToken($alice);

    // As Fortify's reset controller does: the broker checks the emailed token, the password is set, PasswordReset fires.
    $status = Password::broker()->reset(
        ['email' => $alice->email, 'token' => $token, 'password' => 'a fresh password', 'password_confirmation' => 'a fresh password'],
        function ($user, string $password): void {
            $user->forceFill(['password' => bcrypt($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        },
    );

    expect($status)->toBe(Password::PasswordReset)
        ->and($alice->fresh()->email_verified_at)->not->toBeNull();
});

it('leaves an already verified account as it was', function () {
    $bob = host_user('bob');
    $verifiedAt = $bob->email_verified_at;
    $this->travel(1)->hours();

    event(new PasswordReset($bob));

    expect($bob->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
});

it('does not verify on a refused reset (a wrong token)', function () {
    $carol = host_user('carol', verified: false);

    $status = Password::broker()->reset(
        ['email' => $carol->email, 'token' => 'wrong', 'password' => 'a fresh password', 'password_confirmation' => 'a fresh password'],
        fn ($user) => event(new PasswordReset($user)),
    );

    expect($status)->toBe(Password::InvalidToken)
        ->and($carol->fresh()->email_verified_at)->toBeNull();
});
