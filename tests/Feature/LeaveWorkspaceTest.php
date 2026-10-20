<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tey\Concierge\Actions\LeaveWorkspace;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MembershipRemoved;
use Tey\Concierge\Support\Roles;

function leave_denied(string $code): Closure
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

it('lets a member leave: removed by themselves, roles and permissions gone, the user and other workspaces untouched', function () {
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    $elsewhere = join_as($other, $otherOwner, $this->presenter, ['admin']);
    $permission = Permission::findOrCreate('reports.view', Roles::guard());
    app(Concierge::class)->context()->run($this->workspace, fn () => $this->presenter->givePermissionTo($permission), [$this->presenter]);
    Event::fake([MembershipRemoved::class]);

    $left = LeaveWorkspace::run($this->presenter, $this->workspace);

    expect($left->status)->toBe(MembershipStatus::Removed)
        ->and($left->removed_by_id)->toBe($this->presenter->id)
        ->and($left->removed_at)->not->toBeNull()
        ->and(roles_in($left))->toBe([])
        ->and(DB::table('model_has_permissions')->where('model_id', $this->presenter->id)->where('workspace_id', $this->workspace->id)->count())->toBe(0)
        ->and(roles_in($elsewhere))->toBe(['admin'])
        ->and($this->presenter->fresh())->not->toBeNull()
        ->and(app(Concierge::class)->membership($this->presenter, $this->workspace)->isActive())->toBeFalse();

    Event::assertDispatched(MembershipRemoved::class, fn (MembershipRemoved $event) => $event->workspaceId === $this->workspace->id
        && $event->membershipId === $this->presenterMembership->id
        && $event->actorId === $this->presenter->id);
});

it('lets an admin and a suspended member leave', function () {
    SuspendMembership::run($this->owner, $this->presenterMembership);

    expect(LeaveWorkspace::run($this->presenter, $this->workspace)->status)->toBe(MembershipStatus::Removed)
        ->and(LeaveWorkspace::run($this->admin, $this->workspace)->status)->toBe(MembershipStatus::Removed)
        ->and(roles_in($this->adminMembership))->toBe([]);
});

it('refuses the owner: ownership is transferred first, and then they can leave', function () {
    $ownerMembership = app(Concierge::class)->ownerOf($this->workspace);

    expect(fn () => LeaveWorkspace::run($this->owner, $this->workspace))->toThrow(leave_denied('owner_must_transfer'))
        ->and($ownerMembership->fresh()->status)->toBe(MembershipStatus::Active)
        ->and(roles_in($ownerMembership))->toBe(['owner']);

    confirm_recent_authentication();
    TransferOwnership::run($this->owner, $this->adminMembership);

    expect(LeaveWorkspace::run($this->owner, $this->workspace)->status)->toBe(MembershipStatus::Removed)
        ->and(roles_in($this->adminMembership))->toBe(['owner']);
});

it('refuses someone who is not a member, or has already left, and cannot leave another person out', function () {
    $stranger = host_user('stranger');
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));

    expect(fn () => LeaveWorkspace::run($stranger, $this->workspace))->toThrow(leave_denied('not_member'))
        ->and(fn () => LeaveWorkspace::run($otherOwner, $this->workspace))->toThrow(leave_denied('not_member'));

    LeaveWorkspace::run($this->presenter, $this->workspace);

    expect(fn () => LeaveWorkspace::run($this->presenter, $this->workspace))->toThrow(leave_denied('not_member'))
        ->and(app(Concierge::class)->membership($otherOwner, $other)->isActive())->toBeTrue();
});

it('is finished for the member: no management, and a new invitation brings them back', function () {
    LeaveWorkspace::run($this->admin, $this->workspace);

    expect(fn () => issue($this->admin, $this->workspace, 'x@example.test'))->toThrow(leave_denied('not_member'));

    $rejoined = join_as($this->workspace, $this->owner, $this->admin, ['presenter']);

    expect($rejoined->status)->toBe(MembershipStatus::Active)
        ->and($rejoined->removed_by_id)->toBeNull()
        ->and(roles_in($rejoined))->toBe(['presenter']);
});
