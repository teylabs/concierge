<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Events\ScopeRevoked;
use Tey\Concierge\Models\Membership;

/**
 * Ends every active scope of a membership that is being removed, so removing
 * a member (or a member leaving) takes their records with them. Call inside
 * the membership's locks.
 */
final class MembershipScopes
{
    public static function revokeAll(Membership $membership, ?int $actorId): void
    {
        $scopes = $membership->scopes()->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();

        foreach ($scopes as $scope) {
            $scope->forceFill(['active' => null, 'revoked_at' => now(), 'revoked_by_id' => $actorId])->save();

            ScopeRevoked::dispatch(
                (int) $membership->workspace_id,
                (int) $membership->getKey(),
                (int) $scope->getKey(),
                $scope->scope_type,
                $scope->scope_id,
                $actorId,
                true,
            );
        }
    }
}
