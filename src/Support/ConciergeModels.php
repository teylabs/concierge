<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\Ownership;
use Tey\Concierge\Models\TenantInvitation;

final class ConciergeModels
{
    /** @return class-string<Model&Member> */
    public static function user(): string
    {
        /** @var class-string<Model&Member> $class */
        $class = self::host('user', Member::class);

        if (! in_array(HasRoles::class, class_uses_recursive($class), true)) {
            throw new UnsupportedConfiguration("[{$class}] must use Spatie\\Permission\\Traits\\HasRoles.");
        }

        return $class;
    }

    /** @return class-string<Model&Workspace> */
    public static function workspace(): string
    {
        /** @var class-string<Model&Workspace> $class */
        $class = self::host('workspace', Workspace::class);

        return $class;
    }

    /** @return class-string<Membership> */
    public static function membership(): string
    {
        return config('concierge.models.membership', Membership::class);
    }

    /** @return class-string<Ownership> */
    public static function ownership(): string
    {
        return config('concierge.models.ownership', Ownership::class);
    }

    /** @return class-string<Invitation> */
    public static function invitation(): string
    {
        return config('concierge.models.invitation', Invitation::class);
    }

    /** @return class-string<TenantInvitation> */
    public static function tenantInvitation(): string
    {
        return config('concierge.models.tenant_invitation', TenantInvitation::class);
    }

    public static function userTable(): string
    {
        return (new (self::user()))->getTable();
    }

    public static function workspaceTable(): string
    {
        return (new (self::workspace()))->getTable();
    }

    public static function assertTeamsEnabled(): void
    {
        if (! config('permission.teams')) {
            throw new UnsupportedConfiguration('Concierge requires permission.teams = true.');
        }
    }

    /**
     * @param  class-string  $contract
     * @return class-string<Model>
     */
    private static function host(string $key, string $contract): string
    {
        $class = config("concierge.models.{$key}");

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, $contract, true)) {
            throw new UnsupportedConfiguration("concierge.models.{$key} must be an Eloquent model implementing [{$contract}].");
        }

        $model = new $class;

        if (! $model->getIncrementing() || $model->getKeyType() !== 'int') {
            throw new UnsupportedConfiguration("[{$class}] must use an incrementing integer key.");
        }

        return $class;
    }
}
