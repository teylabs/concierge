<?php

namespace Tey\Concierge;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tey\Concierge\Commands\PagesCommand;
use Tey\Concierge\Commands\SyncRolesCommand;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Http\Middleware\RequireActiveMembership;
use Tey\Concierge\LocalAuth\LocalAuthServiceProvider;
use Tey\Concierge\Registration\RegistrationServiceProvider;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConfiguredAccountPolicy;
use Tey\Concierge\Support\ConfiguredGrantPolicy;
use Tey\Concierge\Support\OpenAccountGuard;
use Tey\Concierge\Support\PermissionWorkspaceContext;
use Tey\Concierge\Support\PlatformOwners;
use Tey\Concierge\Support\SessionRecentAuthentication;
use Tey\Concierge\Support\UserDeletionGuard;

class ConciergeServiceProvider extends PackageServiceProvider
{
    public const TENANT_INSPECT_LIMITER = 'concierge-tenant-invitation-inspect';

    public const TENANT_ACCEPT_LIMITER = 'concierge-tenant-invitation-accept';

    public const TENANT_INVITE_LIMITER = 'concierge-tenant-invitation-invite';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('concierge')
            ->hasConfigFile()
            ->hasMigrations(['create_concierge_tables', 'create_concierge_user_accounts_table']);

        // Read with the same caveat as below: only a host's own config is visible.
        if (static::tenantInvitationsEnabled()) {
            $package->hasMigration('create_concierge_tenant_invitations_table');
        }

        // Read with a default: the package defaults are merged after this runs,
        // so only a host's own config/concierge.php can be seen here.
        if (static::membershipEnabled()) {
            $package->runsMigrations();
        }
    }

    /**
     * Membership is on unless the host sets concierge.features.membership to false,
     * e.g. an app that only wants the local-login feature and has no workspaces.
     */
    public static function membershipEnabled(): bool
    {
        return config('concierge.features.membership', true) !== false;
    }

    /**
     * Platform-level tenant invitations are opt-in: no table, limiter or Gate
     * definition exists until the host sets concierge.features.tenant_invitations.
     */
    public static function tenantInvitationsEnabled(): bool
    {
        return static::membershipEnabled() && config('concierge.features.tenant_invitations', false) === true;
    }

    public function packageRegistered(): void
    {
        $this->app->register(LocalAuthServiceProvider::class);
        $this->app->register(RegistrationServiceProvider::class);

        if (! static::membershipEnabled()) {
            return;
        }

        // Resolved once here so an unknown concierge.mode fails at boot, not on first use.
        $mode = Mode::configured();

        // Tenant invitations provision a workspace per invitee, so they need workspaces.
        if ($mode === Mode::App && static::tenantInvitationsEnabled()) {
            throw new UnsupportedConfiguration(
                "concierge.features.tenant_invitations needs concierge.mode = 'workspaces'; the mode is 'app'."
            );
        }

        $this->app->singleton(Concierge::class);
        $this->app->singleton(AppScope::class);
        $this->app->scopedIf(WorkspaceContext::class, $mode === Mode::App ? AppScope::class : PermissionWorkspaceContext::class);
        $this->app->bindIf(GrantPolicy::class, ConfiguredGrantPolicy::class);
        $this->app->bindIf(AccountPolicy::class, ConfiguredAccountPolicy::class);
        $this->app->bindIf(RecentAuthentication::class, SessionRecentAuthentication::class);
        $this->app->bindIf(AccountGuard::class, OpenAccountGuard::class);
    }

    public function packageBooted(): void
    {
        if (! static::membershipEnabled()) {
            return;
        }

        $this->app->make(Router::class)->aliasMiddleware('concierge.member', RequireActiveMembership::class);
        UserDeletionGuard::register();

        AboutCommand::add('Concierge', fn () => array_filter([
            'Mode' => Mode::configured()->value,
            'Deprecated' => Mode::inferred()
                ? "<fg=yellow;options=bold>Mode inferred from models.workspace; set 'mode' => 'workspaces' in config/concierge.php before 1.0</>"
                : null,
        ]));

        if (static::tenantInvitationsEnabled()) {
            $this->bootTenantInvitations();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PagesCommand::class, SyncRolesCommand::class]);
            ConciergeScaffolds::register($this->app->basePath());
        }
    }

    /**
     * Rate limiters are named for the host to apply to its own tenant-less routes;
     * the package registers no routes. Keys combine IP, token hash and user id.
     */
    protected function bootTenantInvitations(): void
    {
        $limit = fn (string $key, int $default) => (int) config("concierge.tenant_invitations.throttle.{$key}", $default);

        RateLimiter::for(self::TENANT_INSPECT_LIMITER, fn (Request $request) => [
            Limit::perMinute($limit('inspect', 30))->by('ip:'.$request->ip()),
            Limit::perMinute($limit('inspect', 30))->by('token:'.hash('sha256', (string) $request->route('token'))),
        ]);

        RateLimiter::for(self::TENANT_ACCEPT_LIMITER, fn (Request $request) => array_filter([
            Limit::perMinute($limit('accept', 10))->by('ip:'.$request->ip()),
            Limit::perMinute($limit('accept', 10))->by('token:'.hash('sha256', (string) $request->route('token'))),
            $request->user() ? Limit::perMinute($limit('accept', 10))->by('user:'.$request->user()->getAuthIdentifier()) : null,
        ]));

        RateLimiter::for(self::TENANT_INVITE_LIMITER, fn (Request $request) => Limit::perMinute($limit('invite', 20))
            ->by('actor:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A host that defines the ability itself (before or after this) wins.
        if (! Gate::has(PlatformOwners::ability())) {
            Gate::define(PlatformOwners::ability(), fn ($user) => $user instanceof Member && PlatformOwners::listed($user));
        }
    }
}
