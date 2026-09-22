<?php

namespace Tey\Concierge;

use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Http\Middleware\RequireActiveMembership;
use Tey\Concierge\Support\ConfiguredGrantPolicy;
use Tey\Concierge\Support\PermissionWorkspaceContext;
use Tey\Concierge\Support\SessionRecentAuthentication;

class ConciergeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('concierge')
            ->hasConfigFile()
            ->hasMigrations(['create_concierge_tables'])
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Concierge::class);
        $this->app->scopedIf(WorkspaceContext::class, PermissionWorkspaceContext::class);
        $this->app->bindIf(GrantPolicy::class, ConfiguredGrantPolicy::class);
        $this->app->bindIf(RecentAuthentication::class, SessionRecentAuthentication::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('concierge.member', RequireActiveMembership::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncRoleDefinitions::class]);
        }
    }
}
