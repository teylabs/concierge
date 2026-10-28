<?php

namespace Tey\Concierge;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Validated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tey\Concierge\Commands\DeactivateUserCommand;
use Tey\Concierge\Commands\ImportMembersCommand;
use Tey\Concierge\Commands\InviteCommand;
use Tey\Concierge\Commands\OwnersCommand;
use Tey\Concierge\Commands\PagesCommand;
use Tey\Concierge\Commands\RestoreUserCommand;
use Tey\Concierge\Commands\RolesCommand;
use Tey\Concierge\Commands\SyncRolesCommand;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Contracts\ScopedAccess;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Http\Middleware\EnsureAccountActive;
use Tey\Concierge\Http\Middleware\RequireActiveMembership;
use Tey\Concierge\Http\Middleware\RequireScopedAccess;
use Tey\Concierge\Kit\KitMembershipSource;
use Tey\Concierge\Kit\KitWorkspaceContext;
use Tey\Concierge\Listeners\RefuseDeactivatedUser;
use Tey\Concierge\Listeners\VerifyEmailOnPasswordReset;
use Tey\Concierge\LocalAuth\LocalAuthServiceProvider;
use Tey\Concierge\Registration\RegistrationServiceProvider;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeFrontend;
use Tey\Concierge\Support\ConciergeMembershipSource;
use Tey\Concierge\Support\ConfiguredAccountPolicy;
use Tey\Concierge\Support\ConfiguredGrantPolicy;
use Tey\Concierge\Support\InvitationHandoff;
use Tey\Concierge\Support\MembershipScopedAccess;
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
            ->hasMigrations(['create_concierge_tables', 'create_concierge_user_accounts_table', 'update_concierge_invitations_with_message', 'update_concierge_invitations_with_acceptor', 'update_concierge_invitations_with_scope']);

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
                "concierge.features.tenant_invitations needs concierge.mode = 'workspaces'; the mode is 'app'. Set 'features.tenant_invitations' => false or 'mode' => 'workspaces' in config/concierge.php."
            );
        }

        $this->app->singleton(Concierge::class);
        $this->app->singleton(AppScope::class);
        $this->registerWorkspaceEngine($mode);
        $this->app->bindIf(GrantPolicy::class, ConfiguredGrantPolicy::class);
        $this->app->bindIf(ScopedAccess::class, MembershipScopedAccess::class);
        $this->app->bindIf(AccountPolicy::class, ConfiguredAccountPolicy::class);
        $this->app->bindIf(RecentAuthentication::class, SessionRecentAuthentication::class);
        $this->app->bindIf(AccountGuard::class, OpenAccountGuard::class);
    }

    /**
     * App mode has no workspace to run in. In workspace mode a starter kit's teams
     * are read through the kit adapter, and concierge's own engine keeps the
     * Spatie-teams context. Hosts may still bind their own.
     */
    protected function registerWorkspaceEngine(Mode $mode): void
    {
        $kit = $mode === Mode::Workspaces && WorkspaceEngine::configured() === WorkspaceEngine::Kit;

        $this->app->scopedIf(WorkspaceContext::class, match (true) {
            $mode === Mode::App => AppScope::class,
            $kit => KitWorkspaceContext::class,
            default => PermissionWorkspaceContext::class,
        });
        $this->app->bindIf(MembershipSource::class, $kit ? KitMembershipSource::class : ConciergeMembershipSource::class);
    }

    public function packageBooted(): void
    {
        // A reset through the emailed link proves the mailbox (README: Security).
        Event::listen(PasswordReset::class, VerifyEmailOnPasswordReset::class);

        if (! static::membershipEnabled()) {
            return;
        }

        $this->app->make(Router::class)->aliasMiddleware('concierge.member', RequireActiveMembership::class);
        $this->app->make(Router::class)->aliasMiddleware('concierge.active', EnsureAccountActive::class);
        $this->app->make(Router::class)->aliasMiddleware('concierge.scoped', RequireScopedAccess::class);
        Event::listen(Validated::class, RefuseDeactivatedUser::class);
        Event::listen(Login::class, [InvitationHandoff::class, 'resume']);
        // The handoff encrypts itself, so a host's cookie encryption must leave it alone.
        EncryptCookies::except(InvitationHandoff::COOKIE);
        UserDeletionGuard::register();

        AboutCommand::add('Concierge', fn () => array_filter([
            'Mode' => Mode::configured()->value,
            'Workspace engine' => Mode::configured() === Mode::Workspaces ? WorkspaceEngine::configured()->value : null,
            'Deprecated' => Mode::inferred()
                ? "<fg=yellow;options=bold>Mode inferred from models.workspace; set 'mode' => 'workspaces' in config/concierge.php before 1.0</>"
                : null,
        ]));

        if (static::tenantInvitationsEnabled()) {
            $this->bootTenantInvitations();
        }

        ConciergeFrontend::register($this->app->basePath());

        if ($this->app->runningInConsole()) {
            $this->commands([DeactivateUserCommand::class, ImportMembersCommand::class, InviteCommand::class, OwnersCommand::class, PagesCommand::class, RestoreUserCommand::class, RolesCommand::class, SyncRolesCommand::class]);
            ConciergeScaffolds::register();
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
