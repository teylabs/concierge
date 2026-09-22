<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\ReinstateMembership;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Support\GrantSet;

function denied_with(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->adminMembership = join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    $this->presenter = host_user('presenter');
    $this->presenterMembership = join_as($this->workspace, $this->owner, $this->presenter, ['presenter']);
});

it('lets admins grant presenter but never admin or owner', function () {
    expect(app(GrantPolicy::class)->grantableRoles($this->admin, $this->workspace))->toBe(['presenter'])
        ->and(app(GrantPolicy::class)->grantableRoles($this->owner, $this->workspace))->toBe(['admin', 'presenter'])
        ->and(fn () => issue($this->admin, $this->workspace, 'x@example.test', ['admin']))->toThrow(denied_with('not_grantable'))
        ->and(fn () => issue($this->owner, $this->workspace, 'x@example.test', ['owner']))->toThrow(denied_with('not_grantable'))
        ->and(fn () => ChangeMemberRoles::run($this->admin, $this->presenterMembership, new GrantSet(['admin'])))->toThrow(denied_with('not_grantable'));

    ChangeMemberRoles::run($this->owner, $this->presenterMembership, new GrantSet(['admin']));

    expect(roles_in($this->presenterMembership))->toBe(['admin']);
});

it('rejects roles outside the configured vocabulary', function () {
    expect(fn () => issue($this->owner, $this->workspace, 'x@example.test', ['super-admin']))->toThrow(denied_with('not_grantable'));
});

it('protects the owner, equal ranks and the actor themselves', function () {
    $ownerMembership = app(Concierge::class)->ownerOf($this->workspace);
    $second = host_user('second-admin');
    $secondMembership = join_as($this->workspace, $this->owner, $second, ['admin']);

    expect(fn () => SuspendMembership::run($this->admin, $ownerMembership))->toThrow(denied_with('owner_protected'))
        ->and(fn () => RemoveMembership::run($this->admin, $ownerMembership))->toThrow(denied_with('owner_protected'))
        ->and(fn () => ChangeMemberRoles::run($this->admin, $ownerMembership, new GrantSet(['presenter'])))->toThrow(denied_with('owner_protected'))
        ->and(fn () => SuspendMembership::run($this->admin, $secondMembership))->toThrow(denied_with('rank'))
        ->and(fn () => SuspendMembership::run($this->admin, $this->adminMembership))->toThrow(denied_with('self'))
        ->and(fn () => SuspendMembership::run($this->presenter, $this->adminMembership))->toThrow(denied_with('not_manager'));
});

it('suspends and reinstates without losing roles', function () {
    $suspended = SuspendMembership::run($this->admin, $this->presenterMembership, 'Left the team');

    expect($suspended->status)->toBe(MembershipStatus::Suspended)
        ->and($suspended->suspension_reason)->toBe('Left the team')
        ->and(fn () => issue($this->presenter, $this->workspace, 'x@example.test'))->toThrow(denied_with('not_member'))
        ->and(SuspendMembership::run($this->admin, $this->presenterMembership)->suspended_at->equalTo($suspended->suspended_at))->toBeTrue();

    $reinstated = ReinstateMembership::run($this->admin, $this->presenterMembership);

    expect($reinstated->status)->toBe(MembershipStatus::Active)
        ->and($reinstated->suspended_at)->toBeNull()
        ->and(roles_in($reinstated))->toBe(['presenter']);
});

it('denies a suspended manager every management action', function () {
    SuspendMembership::run($this->owner, $this->adminMembership);

    expect(fn () => SuspendMembership::run($this->admin, $this->presenterMembership))->toThrow(denied_with('not_member'))
        ->and(fn () => issue($this->admin, $this->workspace, 'x@example.test'))->toThrow(denied_with('not_member'));
});

it('removes membership in one workspace only', function () {
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    $elsewhere = join_as($other, $otherOwner, $this->presenter, ['admin']);

    $removed = RemoveMembership::run($this->owner, $this->presenterMembership);

    expect($removed->status)->toBe(MembershipStatus::Removed)
        ->and($removed->removed_by_id)->toBe($this->owner->id)
        ->and(roles_in($removed))->toBe([])
        ->and(roles_in($elsewhere))->toBe(['admin'])
        ->and($this->presenter->fresh())->not->toBeNull()
        ->and(fn () => ReinstateMembership::run($this->owner, $removed))->toThrow(denied_with('not_member'))
        ->and(fn () => ChangeMemberRoles::run($this->owner, $removed, new GrantSet(['presenter'])))->toThrow(denied_with('not_member'));
});

it('isolates tenants: managers of one workspace cannot touch another', function () {
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    $foreign = join_as($other, $otherOwner, host_user('foreigner'));

    expect(fn () => SuspendMembership::run($this->owner, $foreign))->toThrow(denied_with('not_member'))
        ->and(fn () => RemoveMembership::run($this->admin, $foreign))->toThrow(denied_with('not_member'))
        ->and(fn () => ChangeMemberRoles::run($this->owner, $foreign, new GrantSet(['presenter'])))->toThrow(denied_with('not_member'))
        ->and(fn () => issue($this->owner, $other, 'x@example.test'))->toThrow(denied_with('not_member'))
        ->and($foreign->fresh()->status)->toBe(MembershipStatus::Active);
});

it('keeps role assignments scoped per workspace', function () {
    $other = host_workspace('Other', $this->admin);

    expect(roles_in($this->adminMembership))->toBe(['admin'])
        ->and(roles_in($this->admin->membershipIn($other)))->toBe(['owner'])
        ->and(DB::table('model_has_roles')->where('model_id', $this->admin->id)->pluck('workspace_id')->sort()->values()->all())
        ->toBe([$this->workspace->id, $other->id]);
});
