<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
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

    /** @return class-string<Account> */
    public static function account(): string
    {
        return config('concierge.models.account', Account::class);
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

    /**
     * Each mode has one Spatie teams setting: workspace mode scopes role
     * assignments by team, app-wide mode has no team to scope by.
     */
    public static function assertModeSupported(): void
    {
        if (Mode::configured() === Mode::App) {
            if (config('permission.teams')) {
                throw new UnsupportedConfiguration("Concierge app-wide mode requires permission.teams = false; set 'mode' => 'workspaces' to use teams.");
            }

            return;
        }

        // A starter kit's teams carry their own roles; Spatie holds only the app-level ones (D7).
        if (WorkspaceEngine::configured() === WorkspaceEngine::Kit) {
            if (config('permission.teams')) {
                throw new UnsupportedConfiguration("concierge.workspaces.engine = 'kit' requires permission.teams = false; the kit's team_members.role holds the team roles.");
            }

            return;
        }

        if (! config('permission.teams')) {
            throw new UnsupportedConfiguration('Concierge workspace mode requires permission.teams = true.');
        }
    }

    /**
     * For code that only makes sense with workspaces: names the app-wide
     * action to use instead when the mode is app.
     */
    public static function assertWorkspaces(string $instead): void
    {
        if (Mode::configured() === Mode::App) {
            throw new UnsupportedConfiguration("concierge.mode = 'app' has no workspaces; {$instead}.");
        }
    }

    /** The mirror of assertWorkspaces(), for the app-wide reads and actions. */
    public static function assertAppWide(string $instead): void
    {
        if (Mode::configured() !== Mode::App) {
            throw new UnsupportedConfiguration("concierge.mode = 'workspaces' scopes roles by workspace; {$instead}.");
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
