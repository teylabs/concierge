<?php

namespace Tey\Concierge\Registration;

/**
 * Why a registration was refused, with the sentence shown to the person. Each
 * message says what to do next and never reveals the allowed domains.
 */
enum RegistrationRefusal: string
{
    case Closed = 'closed';
    case InvitationRequired = 'invitation_required';
    case DomainNotAllowed = 'domain_not_allowed';
    case InvitationInvalid = 'invitation_invalid';
    case InvitationExpired = 'invitation_expired';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationForAnotherEmail = 'invitation_for_another_email';

    public function message(): string
    {
        return match ($this) {
            self::Closed => __('Registration is closed.'),
            self::InvitationRequired => __('Registration is by invitation. Use the link in your invitation.'),
            self::DomainNotAllowed => __('Registration is limited to approved email domains. Use your work email address, or the link in your invitation.'),
            self::InvitationInvalid => __('That invitation link is not valid. Use the link in your invitation, or ask to be invited again.'),
            self::InvitationExpired => __('Your invitation has expired. Ask to be invited again.'),
            self::InvitationRevoked => __('Your invitation was revoked. Ask to be invited again.'),
            self::InvitationForAnotherEmail => __('Register with the email address your invitation was sent to.'),
        };
    }
}
