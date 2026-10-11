<?php

namespace Tey\Concierge\Registration;

/**
 * Who may create an account through the app's registration form.
 */
enum RegistrationMode: string
{
    /** Anyone. */
    case Open = 'open';

    /** Anyone whose email is on an allowed domain, or who holds a valid invitation. */
    case Domains = 'domains';

    /** Only someone with a valid invitation for the email they register with. */
    case Invitation = 'invitation';

    /** No one. Accounts come from invitation acceptance or a command. */
    case Closed = 'closed';

    /**
     * Reads concierge.registration.mode. An unknown value fails closed: a typo
     * in an env file must never open registration.
     */
    public static function configured(): self
    {
        $value = config('concierge.registration.mode', self::Invitation->value);

        return self::tryFrom(is_string($value) ? strtolower(trim($value)) : '') ?? self::Closed;
    }
}
