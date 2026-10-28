<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;

/**
 * Concierge roles are global Spatie role rows (team NULL when teams are on),
 * looked up exactly so a same-named workspace role can never be substituted.
 */
final class Roles
{
    /** @return list<string> */
    public static function configured(): array
    {
        return array_keys(config('concierge.roles', []));
    }

    /**
     * Roles flagged 'scoped' => true: only for scoped invitations, never
     * granted workspace-wide.
     *
     * @return list<string>
     */
    public static function scoped(): array
    {
        return array_values(array_filter(self::configured(), self::isScoped(...)));
    }

    public static function isScoped(string $role): bool
    {
        return config("concierge.roles.{$role}.scoped", false) === true;
    }

    public static function owner(): string
    {
        return config('concierge.owner_role', 'owner');
    }

    public static function guard(): string
    {
        return config('concierge.guard') ?? config('auth.defaults.guard');
    }

    public static function rank(string $role): ?int
    {
        $rank = array_search($role, self::configured(), true);

        return $rank === false ? null : $rank;
    }

    /** @param  array<int, string>  $roles */
    public static function highestRank(array $roles): ?int
    {
        $ranks = array_filter(array_map(self::rank(...), $roles), fn ($rank) => $rank !== null);

        return $ranks === [] ? null : min($ranks);
    }

    /**
     * @param  array<int, string>  $roles
     * @return list<string>
     */
    public static function sort(array $roles): array
    {
        usort($roles, fn ($a, $b) => (self::rank($a) ?? PHP_INT_MAX) <=> (self::rank($b) ?? PHP_INT_MAX) ?: strcmp($a, $b));

        return $roles;
    }

    /** @return (Role&Model)|null */
    public static function find(string $name, ?int $id = null): ?Role
    {
        $registrar = app(PermissionRegistrar::class);

        return $registrar->getRoleClass()::query()
            ->when($registrar->teams, fn ($query) => $query->whereNull($registrar->teamsKey))
            ->where('name', $name)
            ->where('guard_name', self::guard())
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->first();
    }

    /**
     * @param  array<int, string>  $names
     * @return list<Role>
     */
    public static function resolve(array $names): array
    {
        return array_map(function (string $name) {
            return self::find($name) ?? throw new UnsupportedConfiguration(in_array($name, self::configured(), true)
                ? "Role [{$name}] is not defined; run php artisan concierge:sync-roles (or SyncRoleDefinitions::run())."
                : "Role [{$name}] is not in concierge.roles; add it to 'roles' in config/concierge.php, then run php artisan concierge:sync-roles."
            );
        }, array_values($names));
    }
}
