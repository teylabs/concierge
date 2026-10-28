<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\RevokeScope;
use Tey\Concierge\Concierge;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\MembershipScope;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\Scope;
use Workbench\App\Models\Document;
use Workbench\App\Models\Workspace;

/**
 * App-wide mode has no workspaces, so no scoped guests: a scope is refused,
 * nothing is ever scoped, the scope table is not created, and a scoped role
 * is never granted app-wide.
 */
it('refuses scoped invitations and allows no scoped access in app-wide mode', function () {
    config()->set('concierge.roles.guest', ['label' => 'Guest', 'grantable_by' => ['owner'], 'scoped' => true]);
    $owner = app_owner();
    $document = Document::query()->create(['title' => 'Handbook']);

    expect(Schema::hasTable('concierge_membership_scopes'))->toBeFalse()
        ->and(Schema::hasColumns('concierge_invitations', ['scope_type', 'scope_id']))->toBeTrue()
        ->and(fn () => InviteMember::run($owner, new Workspace, 'guest@example.test', new GrantSet(['guest']), scope: new Scope('document', '1')))
        ->toThrow(UnsupportedConfiguration::class, "concierge.mode = 'app' has no workspaces; scoped invitations reach one record in a workspace, and app-wide mode has none.")
        ->and(fn () => RevokeScope::run($owner, new MembershipScope))->toThrow(UnsupportedConfiguration::class, 'scoped invitations reach one record')
        ->and(app(Concierge::class)->allowsScoped($owner, $document))->toBeFalse()
        ->and(fn () => invite_user($owner, 'guest@example.test', ['guest']))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('not_grantable'));
});
