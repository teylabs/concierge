<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Tey\Concierge\ConciergeServiceProvider;

// Deliberately database-free (see tests/Pest.php): it asserts what the provider
// registered, not what a shared database happens to contain.
it('leaves an existing host untouched: no migration, limiter or ability', function () {
    $migrationPaths = array_map('basename', app('migrator')->paths());

    expect(ConciergeServiceProvider::tenantInvitationsEnabled())->toBeFalse()
        ->and($migrationPaths)->toContain('create_concierge_tables.php')
        ->and($migrationPaths)->not->toContain('create_concierge_tenant_invitations_table.php')
        ->and(RateLimiter::limiter(ConciergeServiceProvider::TENANT_ACCEPT_LIMITER))->toBeNull()
        ->and(RateLimiter::limiter(ConciergeServiceProvider::TENANT_INVITE_LIMITER))->toBeNull()
        ->and(Gate::has('concierge.manage-tenants'))->toBeFalse();
});
