<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\Mode;

/**
 * Keeps the host's own "delete account" working. concierge_accounts.user_id
 * restricts deletion as a safety net, so when a user model is deleting:
 *
 *  - in app mode, the last active owner is refused with a validation message,
 *    so a starter kit's delete-account form shows it instead of a 500;
 *  - otherwise the user's accounts row is deleted first.
 *
 * A SoftDeletes model is refused the same way, but keeps its accounts row on
 * a soft delete so a restore keeps the state; a force delete removes it.
 * RemoveUser authorises and deletes the row itself, so it passes through here
 * with nothing left to do.
 */
final class UserDeletionGuard
{
    /**
     * The validation key. A starter kit's delete-account form has one field,
     * the password confirmation, and shows errors on it; any other key would
     * be swallowed.
     */
    public const ERROR_KEY = 'password';

    /**
     * Listens on the configured user model's own deleting event, not a
     * wildcard: Spatie's HasRoles detaches the roles in its deleting hook,
     * and class listeners run in registration order before wildcards, so
     * registering here at boot, before the model boots, puts the owner check
     * ahead of that detach. Nothing is registered until the model is configured.
     */
    public static function register(): void
    {
        $class = config('concierge.models.user');

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, Member::class, true)) {
            return;
        }

        Event::listen("eloquent.deleting: {$class}", function (Model&Member $user) {
            (new self)->deleting($user);
        });
    }

    public function deleting(Model&Member $user): void
    {
        if (Mode::configured() === Mode::App && $this->isLastActiveOwner($user)) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('This account is the last owner; make someone else an owner before deleting it.'),
            ]);
        }

        if (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting()) {
            return;
        }

        ConciergeModels::account()::query()->whereKey($user->getKey())->delete();
    }

    /** An owner with no other active owner, shielded or not: looser than the policy, so RemoveUser never hits both. */
    private function isLastActiveOwner(Model&Member $user): bool
    {
        $scope = app(AppScope::class);

        if (! $scope->isOwner($user)) {
            return false;
        }

        foreach ($scope->owners() as $owner) {
            if ((int) $owner->getKey() !== (int) $user->getKey() && $scope->account($owner)->isActive()) {
                return false;
            }
        }

        return true;
    }
}
