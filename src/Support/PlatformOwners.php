<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Tey\Concierge\Contracts\Member;

/**
 * Platform-level authorization, independent of any workspace or permission team.
 * The host decides by defining the configured Gate ability; otherwise the
 * built-in definition checks the configured email list. Unconfigured = denied.
 */
final class PlatformOwners
{
    public static function ability(): string
    {
        return (string) config('concierge.tenant_invitations.ability', 'concierge.manage-tenants');
    }

    public static function allows(Member $actor): bool
    {
        return Gate::forUser($actor)->allows(self::ability());
    }

    public static function authorize(Member $actor): void
    {
        if (! self::allows($actor)) {
            throw new AuthorizationException(__('Only a platform owner can manage tenant invitations.'));
        }
    }

    public static function listed(Member $actor): bool
    {
        $owners = array_map(
            fn ($email) => InvitationRules::normalizeEmail((string) $email),
            (array) config('concierge.tenant_invitations.platform_owners', []),
        );

        return $actor->conciergeHasVerifiedEmail()
            && in_array(InvitationRules::normalizeEmail($actor->conciergeEmail()), $owners, true);
    }
}
