<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\InvitationAccepted;
use Tey\Concierge\Events\MemberInvited;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\GrantSet;

function rejected_as(InvitationState $state): Closure
{
    return fn (InvitationRejected $e) => expect($e->state)->toBe($state);
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
});

it('stores only a hash of the token and scopes invitations to the workspace', function () {
    $issued = issue($this->owner, $this->workspace, 'New.Person@Example.test');

    expect($issued->invitation->email)->toBe('new.person@example.test')
        ->and($issued->invitation->token_hash)->toBe(hash('sha256', $issued->token))
        ->and(DB::table('concierge_invitations')->pluck('token_hash')->all())->not->toContain($issued->token)
        ->and($issued->invitation->expires_at->diffInDays(now()->addDays(7)))->toBeLessThan(1);

    $other = host_workspace('Other', host_user('other-owner'));
    $again = issue($other->owner()->user, $other, 'new.person@example.test');

    expect($again->invitation->workspace_id)->toBe($other->id);
});

it('lets an existing account accept the exact invitation with its roles', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $acceptance = AcceptInvitation::run($alice, $issued->token);

    expect($acceptance->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and($acceptance->membership->status)->toBe(MembershipStatus::Active)
        ->and(roles_in($acceptance->membership))->toBe(['presenter'])
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and($issued->invitation->fresh()->pending_key)->toBeNull();
});

it('rejects the wrong account and unverified accounts without writing anything', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    $mallory = host_user('mallory');

    expect(fn () => AcceptInvitation::run($mallory, $issued->token))->toThrow(rejected_as(InvitationState::WrongAccount));

    $alice = host_user('alice', verified: false);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::Unverified))
        ->and($alice->fresh()->email_verified_at)->toBeNull()
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and(DB::table('concierge_memberships')->count())->toBe(1);
});

it('rejects expired invitations without persisting the expiry', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $this->travel(8)->days();

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::Expired))
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending);
});

it('lazily expires a stale pending invitation when reissuing', function () {
    $first = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(fn () => issue($this->owner, $this->workspace, 'alice@example.test'))->toThrow(ValidationException::class);

    $this->travel(8)->days();
    $second = issue($this->owner, $this->workspace, 'alice@example.test');

    expect($first->invitation->fresh()->status)->toBe(InvitationStatus::Expired)
        ->and($first->invitation->fresh()->pending_key)->toBeNull()
        ->and($second->invitation->isPending())->toBeTrue();
});

it('kills revoked and rotated links', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $this->travel(2)->minutes();
    $resent = ResendInvitation::run($this->owner, $issued->invitation);

    expect($resent->token)->not->toBe($issued->token)
        ->and($resent->invitation->generation)->toBe(2)
        ->and(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::Invalid));

    RevokeInvitation::run($this->owner, $resent->invitation);

    expect(fn () => AcceptInvitation::run($alice, $resent->token))->toThrow(rejected_as(InvitationState::Revoked))
        ->and(fn () => RevokeInvitation::run($this->owner, $resent->invitation))->not->toThrow(Throwable::class);
});

it('enforces a resend cooldown', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(fn () => ResendInvitation::run($this->owner, $issued->invitation))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('cooldown'));
});

it('is idempotent for retries by the accepting user', function () {
    Event::fake([InvitationAccepted::class]);
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $first = AcceptInvitation::run($alice, $issued->token);
    $retry = AcceptInvitation::run($alice, $issued->token);

    expect($retry->outcome)->toBe(AcceptanceOutcome::AlreadyMember)
        ->and($retry->membership->is($first->membership))->toBeTrue()
        ->and(DB::table('concierge_memberships')->where('user_id', $alice->id)->count())->toBe(1);

    Event::assertDispatchedTimes(InvitationAccepted::class, 1);

    expect(fn () => AcceptInvitation::run(host_user('bob'), $issued->token))->toThrow(InvitationRejected::class);
});

it('consumes a reinvite for an active member without rewriting grants', function () {
    $alice = host_user('alice');
    $membership = join_as($this->workspace, $this->owner, $alice, ['presenter']);
    $reinvite = Invitation::query()->create([
        'workspace_id' => $this->workspace->id, 'email' => $alice->email, 'pending_key' => $alice->email,
        'grants' => ['roles' => []], 'grants_fingerprint' => hash('sha256', '[]'), 'inviter_id' => $this->owner->id,
        'token_hash' => Invitation::hashToken('reinvite'), 'status' => InvitationStatus::Pending, 'expires_at' => now()->addDay(),
    ]);

    $acceptance = AcceptInvitation::run($alice, 'reinvite');

    expect($acceptance->outcome)->toBe(AcceptanceOutcome::AlreadyMember)
        ->and(roles_in($membership))->toBe(['presenter'])
        ->and($reinvite->fresh()->status)->toBe(InvitationStatus::Accepted);
});

it('refuses to invite active or suspended members', function () {
    $alice = host_user('alice');
    $membership = join_as($this->workspace, $this->owner, $alice);

    expect(fn () => issue($this->owner, $this->workspace, $alice->email))->toThrow(ValidationException::class);

    SuspendMembership::run($this->owner, $membership);

    expect(fn () => issue($this->owner, $this->workspace, $alice->email))->toThrow(ValidationException::class);
});

it('never lets an invitation bypass a suspension', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);
    $membership = join_as($this->workspace, $this->owner, host_user('bob'));
    DB::table('concierge_memberships')->where('id', $membership->id)->update(['user_id' => $alice->id, 'status' => 'suspended']);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::Suspended));
});

it('lets a removed member rejoin through a fresh invitation', function () {
    $alice = host_user('alice');
    $membership = join_as($this->workspace, $this->owner, $alice);
    RemoveMembership::run($this->owner, $membership);

    $rejoined = join_as($this->workspace, $this->owner, $alice);

    expect($rejoined->is($membership))->toBeTrue()
        ->and($rejoined->status)->toBe(MembershipStatus::Active)
        ->and($rejoined->removed_at)->toBeNull();
});

it('revalidates inviter authority at acceptance', function () {
    $admin = host_user('admin');
    $adminMembership = join_as($this->workspace, $this->owner, $admin, ['admin']);
    $alice = host_user('alice');
    $issued = issue($admin, $this->workspace, $alice->email);

    ChangeMemberRoles::run($this->owner, $adminMembership, new GrantSet(['presenter']));

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::AuthorityLapsed));

    ChangeMemberRoles::run($this->owner, $adminMembership, new GrantSet(['admin']));
    SuspendMembership::run($this->owner, $adminMembership);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::AuthorityLapsed));
});

it('rejects grants whose roles gained permissions or were recreated after issuance', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    Role::query()->whereNull('workspace_id')->where('name', 'presenter')->first()
        ->givePermissionTo(Permission::create(['name' => 'billing.manage', 'guard_name' => 'web']));

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::GrantsChanged));

    $bob = host_user('bob');
    $second = issue($this->owner, $this->workspace, $bob->email);
    Role::query()->whereNull('workspace_id')->where('name', 'presenter')->first()->delete();
    Role::create(['name' => 'presenter', 'guard_name' => 'web', 'workspace_id' => null]);

    expect(fn () => AcceptInvitation::run($bob, $second->token))->toThrow(rejected_as(InvitationState::GrantsChanged));
});

it('rejects acceptance when the workspace no longer accepts members', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);
    $this->workspace->update(['accepts_members' => false]);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(rejected_as(InvitationState::WorkspaceUnavailable));
});

it('rejects unknown tokens', function () {
    expect(fn () => AcceptInvitation::run(host_user('alice'), 'nope'))->toThrow(rejected_as(InvitationState::Invalid));
});

it('dispatches scalar events after commit without tokens', function () {
    Event::fake([MemberInvited::class]);

    DB::transaction(function () {
        issue($this->owner, $this->workspace, 'alice@example.test');
        Event::assertNotDispatched(MemberInvited::class);
    });

    Event::assertDispatched(MemberInvited::class, function (MemberInvited $event) {
        return collect(get_object_vars($event))->every(fn ($value) => is_int($value) || $value === null);
    });
});
