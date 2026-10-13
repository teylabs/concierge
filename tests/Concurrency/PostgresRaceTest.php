<?php

use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\AcceptTenantInvitation;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Actions\RevokeTenantInvitation;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\Workspace;

require_once __DIR__.'/../Support/Concurrency.php';
require_once __DIR__.'/../Support/LocalAuth/requests.php';

local_login_preserve_process_environment();

beforeEach(function () {
    if (! TestCase::usesPostgres() || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Concurrency claims require PostgreSQL (CONCIERGE_TEST_DB=pgsql) and pcntl.');
    }

    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    confirm_recent_authentication();
});

it('accepts one invitation exactly once under concurrent retries', function () {
    $alice = host_user('alice');
    $token = issue($this->owner, $this->workspace, $alice->email)->token;

    $results = race($this->workspace->id, [
        'first' => fn () => AcceptInvitation::run($alice, $token)->outcome->value,
        'second' => fn () => AcceptInvitation::run($alice, $token)->outcome->value,
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['already_member', 'joined'])
        ->and(DB::table('concierge_memberships')->where('user_id', $alice->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('model_id', $alice->id)->count())->toBe(1);
});

it('signs in two local accounts concurrently but establishes only one workspace owner', function () {
    local_login_enable();
    $workspace = host_workspace();
    $accounts = [host_user('local'), host_user('second')];

    // This workspace must be oldest, ahead of the owned workspace from beforeEach.
    $workspace->forceFill(['created_at' => now()->subDay()])->save();
    $click = function ($account): string {
        config(['concierge.local_login.account.email' => $account->email]);

        return (string) local_login_click()->status();
    };

    $results = race($workspace->id, [
        'first' => fn () => $click($accounts[0]),
        'second' => fn () => $click($accounts[1]),
    ]);

    expect($results)->toBe(['first' => '302', 'second' => '302'])
        ->and(DB::table('concierge_memberships')->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(roles_in(app(Concierge::class)->ownerOf($workspace)))->toBe(['owner']);
});

it('serializes acceptance against revocation', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $results = race($this->workspace->id, [
        'accept' => fn () => AcceptInvitation::run($alice, $issued->token)->outcome->value,
        'revoke' => fn () => RevokeInvitation::run($this->owner, $issued->invitation)->status->value,
    ]);

    $invitation = $issued->invitation->fresh();
    $membership = $alice->membershipIn($this->workspace);

    if ($results['accept'] === 'joined') {
        expect($results['revoke'])->toBe('ValidationException:0')
            ->and($invitation->status)->toBe(InvitationStatus::Accepted)
            ->and($membership->status)->toBe(MembershipStatus::Active);
    } else {
        expect($results)->toBe(['accept' => 'InvitationRejected:revoked', 'revoke' => 'revoked'])
            ->and($invitation->status)->toBe(InvitationStatus::Revoked)
            ->and($membership)->toBeNull();
    }
});

it('never produces two owners from racing transfers', function () {
    $x = join_as($this->workspace, $this->owner, host_user('x'), ['admin']);
    $y = join_as($this->workspace, $this->owner, host_user('y'), ['admin']);

    $results = race($this->workspace->id, [
        'x' => fn () => TransferOwnership::run($this->owner, $x)->id,
        'y' => fn () => TransferOwnership::run($this->owner, $y)->id,
    ]);

    expect(collect($results)->filter(fn ($r) => $r === 'AuthorizationException:not_owner'))->toHaveCount(1)
        ->and(DB::table('concierge_ownerships')->where('workspace_id', $this->workspace->id)->count())->toBe(1);

    $owners = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.workspace_id', $this->workspace->id)
        ->where('roles.name', 'owner')
        ->pluck('model_id')->all();

    expect($owners)->toBe([app(Concierge::class)->ownerOf($this->workspace)->user_id]);
});

it('never suspends the owner when a transfer races a suspension', function () {
    $admin = host_user('admin');
    join_as($this->workspace, $this->owner, $admin, ['admin']);
    $recipient = join_as($this->workspace, $this->owner, host_user('recipient'), ['presenter']);

    $results = race($this->workspace->id, [
        'transfer' => fn () => (string) TransferOwnership::run($this->owner, $recipient)->id,
        'suspend' => fn () => SuspendMembership::run($admin, $recipient)->status->value,
    ]);

    $owner = app(Concierge::class)->ownerOf($this->workspace);

    expect($owner->status)->toBe(MembershipStatus::Active)
        ->and(in_array($results, [
            ['transfer' => (string) $recipient->id, 'suspend' => 'AuthorizationException:owner_protected'],
            ['transfer' => 'AuthorizationException:recipient_invalid', 'suspend' => 'suspended'],
        ], true))->toBeTrue();
});

it('provisions exactly one workspace when a tenant invitation is accepted concurrently', function () {
    app(Concierge::class)->provisionWorkspaceUsing(
        fn ($owner) => Workspace::query()->create(['name' => "{$owner->name}'s workspace"]),
    );
    $platform = host_user('platform');
    config(['concierge.tenant_invitations.platform_owners' => ['platform@example.test']]);
    $peer = host_user('peer');
    $issued = InviteTenant::run($platform, $peer->email);
    $before = DB::table('workspaces')->count();

    $results = race($issued->invitation->id, [
        'first' => fn () => AcceptTenantInvitation::run($peer, $issued->token)->outcome->value,
        'second' => fn () => AcceptTenantInvitation::run($peer, $issued->token)->outcome->value,
    ], 'concierge_tenant_invitations');

    expect(collect($results)->sort()->values()->all())->toBe(['already_provisioned', 'provisioned'])
        ->and(DB::table('workspaces')->count())->toBe($before + 1)
        ->and(DB::table('concierge_ownerships')->count())->toBe(2) // the seeded Acme and the peer's
        ->and(DB::table('concierge_memberships')->where('user_id', $peer->id)->count())->toBe(1);
});

it('serializes tenant acceptance against revocation', function () {
    app(Concierge::class)->provisionWorkspaceUsing(
        fn ($owner) => Workspace::query()->create(['name' => "{$owner->name}'s workspace"]),
    );
    $platform = host_user('platform');
    config(['concierge.tenant_invitations.platform_owners' => ['platform@example.test']]);
    $peer = host_user('peer');
    $issued = InviteTenant::run($platform, $peer->email);
    $before = DB::table('workspaces')->count();

    $results = race($issued->invitation->id, [
        'accept' => fn () => AcceptTenantInvitation::run($peer, $issued->token)->outcome->value,
        'revoke' => fn () => RevokeTenantInvitation::run($platform, $issued->invitation)->status->value,
    ], 'concierge_tenant_invitations');

    $row = $issued->invitation->fresh();

    if ($results['accept'] === 'provisioned') {
        expect($row->status)->toBe(InvitationStatus::Accepted)
            ->and($results['revoke'])->toStartWith('ValidationException')
            ->and(DB::table('workspaces')->count())->toBe($before + 1);
    } else {
        expect($row->status)->toBe(InvitationStatus::Revoked)
            ->and($results['accept'])->toContain('InvitationRejected')
            ->and($row->workspace_id)->toBeNull()
            ->and(DB::table('workspaces')->count())->toBe($before);
    }
});
