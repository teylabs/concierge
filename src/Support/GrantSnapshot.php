<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Models\Invitation;

/**
 * Freezes the role ids and permission names an invitation may grant, so a
 * role that is recreated or gains permissions after issuance is rejected.
 * A scoped invitation's record is part of the fingerprint, so the same roles
 * for another record are a different grant.
 */
final class GrantSnapshot
{
    /** @return array{grants: array{roles: array<string, array{id: int, permissions: list<string>}>, scope?: array{type: string, id: string}}, fingerprint: string}|null */
    public static function capture(GrantSet $grants, ?Scope $scope = null): ?array
    {
        $roles = [];

        foreach ($grants->roles as $name) {
            $role = Roles::find($name);

            if (! $role) {
                return null;
            }

            $roles[$name] = ['id' => (int) $role->getKey(), 'permissions' => self::permissionsOf($role)];
        }

        ksort($roles);

        return [
            'grants' => ['roles' => $roles, ...($scope ? ['scope' => $scope->toArray()] : [])],
            'fingerprint' => self::fingerprint($roles, $scope),
        ];
    }

    public static function stillValid(Invitation $invitation): bool
    {
        $roles = $invitation->grants['roles'] ?? [];
        $snapshotScope = isset($invitation->grants['scope']) ? new Scope((string) $invitation->grants['scope']['type'], (string) $invitation->grants['scope']['id']) : null;
        $scope = $invitation->scope();

        if (($scope === null) !== ($snapshotScope === null) || ($scope && ! $scope->is($snapshotScope))) {
            return false;
        }

        if (! hash_equals($invitation->grants_fingerprint, self::fingerprint($roles, $scope))) {
            return false;
        }

        foreach ($roles as $name => $snapshot) {
            $role = Roles::find($name, $snapshot['id']);

            if (! $role || array_diff(self::permissionsOf($role), $snapshot['permissions']) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function permissionsOf(object $role): array
    {
        $permissions = $role->permissions()->pluck('name')->map(fn ($name) => (string) $name)->all();
        sort($permissions);

        return $permissions;
    }

    /** @param  array<string, array{id: int, permissions: list<string>}>  $roles */
    private static function fingerprint(array $roles, ?Scope $scope = null): string
    {
        ksort($roles);

        $canonical = array_map(fn (array $role) => [(int) $role['id'], $role['permissions']], $roles);

        // Unscoped grants keep the fingerprint they always had.
        if ($scope !== null) {
            $canonical = ['roles' => $canonical, 'scope' => [$scope->type, $scope->id]];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
