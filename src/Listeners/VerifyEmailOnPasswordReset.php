<?php

namespace Tey\Concierge\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Tey\Concierge\Support\VerifiedEmail;

/**
 * A password reset completed through the emailed, signed link proves control of the mailbox, so an unverified account becomes verified. This covers
 * the first owner from concierge:owners (whose set-password link is a reset link) and anyone else who resets before verifying.
 */
class VerifyEmailOnPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        VerifiedEmail::mark($event->user);
    }
}
