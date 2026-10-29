<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Data\UI\RoleOption;

/** The role checklist for a form: each role's label and description from the config. */
final class RoleOptions
{
    /**
     * @param  list<string>  $roles
     * @return list<RoleOption>
     */
    public static function for(array $roles): array
    {
        return array_map(static fn (string $role) => new RoleOption(
            $role,
            (string) config("concierge.roles.{$role}.label", ucfirst($role)),
            is_string($description = config("concierge.roles.{$role}.description")) ? $description : null,
        ), Roles::sort($roles));
    }
}
