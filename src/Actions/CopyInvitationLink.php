<?php

namespace Tey\Concierge\Actions;

use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\IssuedInvitation;

/**
 * A fresh link to hand over in person: the token rotates as on resend, so the
 * previous link stops working, but nothing is emailed and there is no
 * cooldown. Only someone who could resend the invitation may copy it.
 */
class CopyInvitationLink
{
    use Runnable;

    public function __construct(protected ResendInvitation $resend) {}

    public function handle(Member $actor, Invitation $invitation): IssuedInvitation
    {
        return $this->resend->rotate($actor, $invitation, null, deliver: false);
    }
}
