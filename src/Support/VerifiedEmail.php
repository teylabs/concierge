<?php

namespace Tey\Concierge\Support;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks an account's email verified once Concierge has proof of the mailbox: an accepted invitation (the token was emailed to that address), an
 * invited registration, or a password reset through the emailed link. Never for an unproven address.
 */
final class VerifiedEmail
{
    public static function mark(object $user): void
    {
        if ($user instanceof MustVerifyEmail) {
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return;
        }
        if ($user instanceof Model && $user->getAttribute('email_verified_at') === null) {
            $user->forceFill(['email_verified_at' => $user->freshTimestamp()])->save();
        }
    }
}
