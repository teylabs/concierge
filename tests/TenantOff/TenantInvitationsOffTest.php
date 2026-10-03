<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\ConciergeServiceProvider;

it('leaves an existing host untouched: no table, limiter or ability', function () {
    expect(ConciergeServiceProvider::tenantInvitationsEnabled())->toBeFalse()
        ->and(Schema::hasTable('concierge_tenant_invitations'))->toBeFalse()
        ->and(Schema::hasTable('concierge_invitations'))->toBeTrue()
        ->and(RateLimiter::limiter(ConciergeServiceProvider::TENANT_ACCEPT_LIMITER))->toBeNull()
        ->and(Gate::has('concierge.manage-tenants'))->toBeFalse();
});

it('refuses to invite while the feature is off, whoever asks', function () {
    config(['concierge.tenant_invitations.platform_owners' => ['platform@example.test']]);

    expect(fn () => InviteTenant::run(host_user('platform'), 'peer@example.test'))
        ->toThrow(AuthorizationException::class);
});
