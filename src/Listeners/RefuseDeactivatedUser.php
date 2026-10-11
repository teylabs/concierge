<?php

namespace Tey\Concierge\Listeners;

use Illuminate\Auth\Events\Validated;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;

/**
 * Laravel fires Validated once the credentials check out and before the
 * session starts, for Fortify, the starter kits and any Auth::attempt. Refusing
 * here means a deactivated user never signs in.
 */
class RefuseDeactivatedUser
{
    public function __construct(protected Concierge $concierge) {}

    public function handle(Validated $event): void
    {
        if ($event->user instanceof Member && ! $this->concierge->account($event->user)->isActive()) {
            throw ValidationException::withMessages(['email' => __('This account is deactivated.')]);
        }
    }
}
