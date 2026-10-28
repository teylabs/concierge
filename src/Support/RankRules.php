<?php

namespace Tey\Concierge\Support;

/**
 * The configured role rules, over role names alone: who may grant which role
 * (`grantable_by`), who manages (`managers`), and who outranks whom (the order
 * of `roles`). A policy supplies the role names; these rules know no scope.
 */
final class RankRules
{
    /**
     * @param  array<int, string>  $held
     * @return list<string>
     */
    public static function grantable(array $held): array
    {
        return array_values(array_filter(
            Roles::configured(),
            fn (string $role) => $role !== Roles::owner()
                && ! Roles::isScoped($role)
                && array_intersect(config("concierge.roles.{$role}.grantable_by", []), $held) !== [],
        ));
    }

    /**
     * The scoped roles (see Roles::scoped()) the held roles may grant with a
     * scoped invitation. They are never part of grantable().
     *
     * @param  array<int, string>  $held
     * @return list<string>
     */
    public static function grantableScoped(array $held): array
    {
        return array_values(array_filter(
            Roles::scoped(),
            fn (string $role) => array_intersect(config("concierge.roles.{$role}.grantable_by", []), $held) !== [],
        ));
    }

    /** @param  array<int, string>  $held */
    public static function manages(array $held): bool
    {
        return array_intersect(config('concierge.managers', []), $held) !== [];
    }

    /**
     * A target with no configured role is outranked by anyone; otherwise the
     * actor's highest role must rank strictly above the target's.
     *
     * @param  array<int, string>  $actor
     * @param  array<int, string>  $target
     */
    public static function outranks(array $actor, array $target): bool
    {
        $targetRank = Roles::highestRank($target);
        $actorRank = Roles::highestRank($actor);

        return $targetRank === null || ($actorRank !== null && $actorRank < $targetRank);
    }
}
