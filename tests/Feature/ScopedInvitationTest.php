<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeScope;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\ScopedAccess;
use Tey\Concierge\Contracts\Workspace as WorkspaceContract;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\InvitationRevoked;
use Tey\Concierge\Events\MembershipRemoved;
use Tey\Concierge\Events\ScopeAccepted;
use Tey\Concierge\Events\ScopeRevoked;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Facades\Concierge as ConciergeFacade;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\MembershipScope;
use Tey\Concierge\Support\ConciergeMembershipSource;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\MembersIndex;
use Tey\Concierge\Support\Scope;
use Workbench\App\Models\Talk;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

/** The members index as the owner's page asks for it, optionally with scoped guests. */
function scoped_members_index(object $actor, object $workspace, array $query = [], bool $guests = false): array
{
    return app(MembersIndex::class)
        ->includingScopedGuests($guests)
        ->urlsUsing(fn (string $action, $subject) => '/'.$action.($subject ? '/'.($subject->public_id ?? $subject->getKey()) : ''))
        ->props($actor, $workspace, Request::create('/members', 'GET', $query))
        ->toArray();
}

/** @return list<string> */
function scoped_member_names(array $props): array
{
    return array_map(fn (array $row) => $row['user']['name'], $props['members']);
}

function invite_scoped(Member $actor, Workspace $workspace, string $email, Model|Scope $scope, array $roles = ['guest']): IssuedInvitation
{
    return InviteMember::run($actor, $workspace, $email, new GrantSet($roles), scope: $scope);
}

function join_scoped(Workspace $workspace, User $actor, User $user, Model|Scope $scope): Membership
{
    return AcceptInvitation::run($user, invite_scoped($actor, $workspace, $user->email, $scope)->token)->membership;
}

function scope_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

beforeEach(function () {
    config()->set('concierge.roles.guest', ['label' => 'Guest', 'grantable_by' => ['owner', 'admin'], 'scoped' => true]);

    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->adminMembership = join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    $this->talk = Talk::query()->create(['workspace_id' => $this->workspace->id, 'title' => 'Keynote']);
    $this->otherTalk = Talk::query()->create(['workspace_id' => $this->workspace->id, 'title' => 'Closing']);
    $this->guest = host_user('guest');
    $this->concierge = app(Concierge::class);
});

it('creates the scope table and its one-active-row index', function () {
    $indexes = collect(Schema::getIndexes('concierge_membership_scopes'))->map(fn (array $i) => implode(',', $i['columns']).($i['unique'] ? ' unique' : ''))->sort()->values()->all();

    expect(Schema::getColumnListing('concierge_membership_scopes'))->toBe(['id', 'membership_id', 'scope_type', 'scope_id', 'active', 'invitation_id', 'invited_by_id', 'accepted_at', 'revoked_at', 'revoked_by_id', 'created_at', 'updated_at'])
        ->and($indexes)->toBe(['id unique', 'membership_id,scope_type,scope_id,active unique', 'scope_type,scope_id']);
});

it('lets a scoped guest reach only the scoped record and nothing workspace-wide', function () {
    Permission::query()->create(['name' => 'talks.present', 'guard_name' => 'web']);
    $membership = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    $can = fn (string $ability) => $this->concierge->context()->run($this->workspace, fn () => $this->guest->fresh()->hasPermissionTo($ability));

    expect($membership->isActive())->toBeTrue()
        ->and($this->concierge->isScopedGuest($membership))->toBeTrue()
        ->and(roles_in($membership))->toBe(['guest'])
        ->and($can('talks.present'))->toBeFalse()
        ->and($this->concierge->allowsScoped($this->guest, $this->talk))->toBeTrue()
        ->and($this->concierge->allowsScoped($this->guest, $this->otherTalk))->toBeFalse()
        ->and($this->concierge->allowsScoped($this->admin, $this->talk))->toBeFalse()
        ->and(app(ScopedAccess::class)->allows($this->guest, $this->talk))->toBeTrue()
        ->and($this->concierge->scopedTo($membership, $this->talk))->toBeTrue()
        ->and($this->concierge->scopes($membership)->map(fn (MembershipScope $s) => [$s->scope_type, $s->scope_id])->all())->toBe([[Talk::class, (string) $this->talk->id]]);

    // The same record id in another workspace is not reached.
    $globex = host_workspace('Globex', host_user('globex-owner'));
    $elsewhere = Talk::query()->create(['workspace_id' => $globex->id, 'title' => 'Elsewhere']);
    $elsewhere->forceFill(['id' => $this->talk->id]);
    expect($this->concierge->allowsScoped($this->guest, $elsewhere))->toBeFalse()
        ->and($this->concierge->allowsScoped($this->guest, $this->talk, $globex))->toBeFalse();

    // A record without workspace_id falls back to the current workspace context.
    $loose = Talk::query()->create(['workspace_id' => null, 'title' => 'Loose']);
    $looseMembership = join_scoped($this->workspace, $this->owner, $this->guest, $loose);
    expect($looseMembership->is($membership))->toBeTrue()
        ->and($this->concierge->allowsScoped($this->guest, $loose))->toBeFalse()
        ->and($this->concierge->context()->run($this->workspace, fn () => $this->concierge->allowsScoped($this->guest, $loose)))->toBeTrue();
});

it('guards routes: concierge.scoped admits only the scoped record, concierge.member refuses guests', function () {
    join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);
    $presenter = host_user('presenter');
    join_as($this->workspace, $this->owner, $presenter);

    Route::middleware([SubstituteBindings::class, 'concierge.scoped:talk'])->get('/talks/{talk}', fn (Talk $talk) => 'ok');
    Route::middleware([SubstituteBindings::class, 'concierge.scoped:talk,members'])->get('/shared/{talk}', fn (Talk $talk) => 'ok');
    Route::middleware('concierge.member')->get('/workspace-home', fn () => 'ok');

    $inWorkspace = fn (Closure $call) => $this->concierge->context()->run($this->workspace, $call);

    $this->actingAs($this->guest)->get("/talks/{$this->talk->id}")->assertOk();
    $this->actingAs($this->guest)->get("/talks/{$this->otherTalk->id}")->assertForbidden();
    $inWorkspace(fn () => $this->actingAs($this->guest)->get('/workspace-home'))->assertForbidden();
    $inWorkspace(fn () => $this->actingAs($presenter)->get('/workspace-home'))->assertOk();

    // Full members are not scoped; the members flag lets them through a shared route.
    $this->actingAs($presenter)->get("/talks/{$this->talk->id}")->assertForbidden();
    $inWorkspace(fn () => $this->actingAs($presenter)->get("/shared/{$this->talk->id}"))->assertOk();
    $inWorkspace(fn () => $this->actingAs($this->guest)->get("/shared/{$this->otherTalk->id}"))->assertForbidden();
    $inWorkspace(fn () => $this->actingAs($this->guest)->get("/shared/{$this->talk->id}"))->assertOk();
});

it('lets the host rebind ScopedAccess, and the service path follows it', function () {
    app()->instance(ScopedAccess::class, new class implements ScopedAccess
    {
        public function allows(Member $user, Model $record, ?WorkspaceContract $workspace = null): bool
        {
            return $record instanceof Talk && $record->title === 'Closing';
        }
    });

    expect(ConciergeFacade::allowsScoped($this->guest, $this->otherTalk))->toBeTrue()
        ->and(ConciergeFacade::scopedAccess()->allows($this->guest, $this->talk))->toBeFalse();
});

it('denies at once once the membership is suspended', function () {
    $membership = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    SuspendMembership::run($this->owner, $membership);

    expect($this->concierge->allowsScoped($this->guest, $this->talk))->toBeFalse();
});

it('stores the scope with a morph map alias and puts it in the grants fingerprint', function () {
    Relation::morphMap(['talk' => Talk::class]);

    try {
        $issued = invite_scoped($this->owner, $this->workspace, 'guest@example.test', $this->talk);
        $keynote = $issued->invitation;
        $closing = invite_scoped($this->owner, $this->workspace, 'guest@example.test', $this->otherTalk)->invitation;
        $plain = issue($this->owner, $this->workspace, 'guest@example.test')->invitation;

        expect([$keynote->scope_type, $keynote->scope_id])->toBe(['talk', (string) $this->talk->id])
            ->and($keynote->grants['scope'])->toBe(['type' => 'talk', 'id' => (string) $this->talk->id])
            ->and($keynote->grants_fingerprint)->not->toBe($closing->grants_fingerprint)
            ->and($keynote->grants_fingerprint)->not->toBe(invite_scoped($this->owner, $this->workspace, 'other@example.test', new Scope('talk', 'x'))->invitation->grants_fingerprint)
            ->and($plain->scope_type)->toBeNull()
            ->and($plain->isScoped())->toBeFalse()
            ->and(fn () => invite_scoped($this->owner, $this->workspace, 'guest@example.test', $this->talk))
            ->toThrow(ValidationException::class, 'An invitation is already pending for that email.');

        // A scope swapped on the row no longer matches its snapshot.
        $keynote->forceFill(['scope_id' => (string) $this->otherTalk->id])->save();
        expect(InspectInvitation::run($this->guest, $issued->token)->state)->toBe(InvitationState::GrantsChanged);
    } finally {
        Relation::$morphMap = [];
    }
});

it('needs scoped roles for a scoped invitation and never grants them workspace-wide', function () {
    $presenter = join_as($this->workspace, $this->owner, host_user('presenter'));

    expect(fn () => invite_scoped($this->owner, $this->workspace, 'x@example.test', $this->talk, ['presenter']))
        ->toThrow(ValidationException::class, 'A scoped invitation grants scoped roles only.')
        ->and(fn () => invite_scoped($this->owner, $this->workspace, 'x@example.test', $this->talk, []))
        ->toThrow(ValidationException::class, 'A scoped invitation grants scoped roles only.')
        ->and(fn () => issue($this->owner, $this->workspace, 'x@example.test', ['guest']))->toThrow(scope_denied('not_grantable'))
        ->and(fn () => ChangeMemberRoles::run($this->owner, $presenter, new GrantSet(['guest'])))->toThrow(scope_denied('not_grantable'))
        ->and(app(GrantPolicy::class)->grantableRoles($this->owner, $this->workspace))->not->toContain('guest')
        ->and(fn () => invite_scoped($presenter->user, $this->workspace, 'x@example.test', $this->talk))->toThrow(scope_denied('not_manager'));
});

it('adds a second scope to the same guest membership, and a scope to a full member without making them a guest', function () {
    Event::fake([ScopeAccepted::class]);

    $first = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);
    $second = join_scoped($this->workspace, $this->admin, $this->guest, $this->otherTalk);

    expect($second->is($first))->toBeTrue()
        ->and($this->concierge->scopes($second)->pluck('scope_id')->all())->toBe([(string) $this->talk->id, (string) $this->otherTalk->id])
        ->and(roles_in($second))->toBe(['guest'])
        ->and(fn () => invite_scoped($this->owner, $this->workspace, $this->guest->email, $this->talk))
        ->toThrow(ValidationException::class, 'That person already has access to it.');

    $full = join_scoped($this->workspace, $this->owner, $this->admin, $this->talk);

    expect($full->is($this->adminMembership))->toBeTrue()
        ->and($this->concierge->isScopedGuest($full->fresh()))->toBeFalse()
        ->and(roles_in($full))->toBe(['admin'])
        ->and($this->concierge->allowsScoped($this->admin, $this->talk))->toBeTrue();

    Event::assertDispatched(ScopeAccepted::class, 3);
    Event::assertDispatched(ScopeAccepted::class, fn (ScopeAccepted $e) => $e->membershipId === $first->id
        && $e->scopeType === Talk::class
        && $e->scopeId === (string) $this->otherTalk->id
        && $e->userId === $this->guest->id
        && $e->workspaceId === $this->workspace->id);
});

it('makes a guest a full member when they accept an unscoped invitation, keeping their scopes', function () {
    $membership = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    $outcome = AcceptInvitation::run($this->guest, issue($this->owner, $this->workspace, $this->guest->email)->token);

    expect($outcome->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and($outcome->membership->is($membership))->toBeTrue()
        ->and($this->concierge->isScopedGuest($outcome->membership->fresh()))->toBeFalse()
        ->and(roles_in($membership))->toBe(['presenter'])
        ->and($this->concierge->scopedTo($membership, $this->talk))->toBeTrue();
});

it('revokes a scope at once, killing pending links and removing the guest membership with its last scope', function () {
    Event::fake([ScopeRevoked::class, MembershipRemoved::class, InvitationRevoked::class]);
    config()->set('concierge.invitations.match_email', false);

    // A pending invitation to the guest's own email for the keynote, issued before they reach it
    // through an invitation sent to another address.
    $pending = invite_scoped($this->owner, $this->workspace, $this->guest->email, $this->talk);
    $membership = AcceptInvitation::run($this->guest, invite_scoped($this->owner, $this->workspace, 'guest.alias@example.test', $this->talk)->token)->membership;
    join_scoped($this->workspace, $this->owner, $this->guest, $this->otherTalk);

    RevokeScope::run($this->admin, $this->concierge->scopeFor($membership, $this->talk));

    expect($this->concierge->allowsScoped($this->guest, $this->talk))->toBeFalse()
        ->and($this->concierge->allowsScoped($this->guest, $this->otherTalk))->toBeTrue()
        ->and($membership->fresh()->status)->toBe(MembershipStatus::Active)
        ->and($pending->invitation->fresh()->status)->toBe(InvitationStatus::Revoked)
        ->and(fn () => AcceptInvitation::run($this->guest, $pending->token))->toThrow(InvitationRejected::class);

    Event::assertDispatched(ScopeRevoked::class, fn (ScopeRevoked $e) => $e->scopeId === (string) $this->talk->id && ! $e->membershipRemoved && $e->actorId === $this->admin->id);
    Event::assertDispatched(InvitationRevoked::class, fn (InvitationRevoked $e) => $e->invitationId === $pending->invitation->id);
    Event::assertNotDispatched(MembershipRemoved::class);

    $last = $this->concierge->scopeFor($membership, $this->otherTalk);
    RevokeScope::run($this->admin, $last);
    RevokeScope::run($this->admin, $last);

    expect($membership->fresh()->status)->toBe(MembershipStatus::Removed)
        ->and(roles_in($membership))->toBe([])
        ->and($this->concierge->allowsScoped($this->guest, $this->otherTalk))->toBeFalse()
        ->and(User::query()->find($this->guest->id))->not->toBeNull()
        ->and($this->concierge->account($this->guest)->isActive())->toBeTrue()
        ->and(MembershipScope::query()->where('membership_id', $membership->id)->whereNotNull('revoked_at')->count())->toBe(2);

    Event::assertDispatched(ScopeRevoked::class, 2);
    Event::assertDispatched(ScopeRevoked::class, fn (ScopeRevoked $e) => $e->scopeId === (string) $this->otherTalk->id && $e->membershipRemoved);
    Event::assertDispatched(MembershipRemoved::class, fn (MembershipRemoved $e) => $e->membershipId === $membership->id);

    // They can be invited to the record again.
    $again = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);
    expect($again->is($membership))->toBeTrue()
        ->and($again->isActive())->toBeTrue()
        ->and($this->concierge->allowsScoped($this->guest, $this->talk))->toBeTrue();
});

it('keeps a full member when their last scope is revoked, and only managers revoke', function () {
    $full = join_scoped($this->workspace, $this->owner, $this->admin, $this->talk);
    $presenter = join_as($this->workspace, $this->owner, host_user('presenter'));

    expect(fn () => RevokeScope::run($presenter->user, $this->concierge->scopeFor($full, $this->talk)))->toThrow(scope_denied('not_manager'));

    RevokeScope::run($this->owner, $this->concierge->scopeFor($full, $this->talk));

    expect($full->fresh()->status)->toBe(MembershipStatus::Active)
        ->and(roles_in($full))->toBe(['admin'])
        ->and($this->concierge->allowsScoped($this->admin, $this->talk))->toBeFalse();
});

it('revokes the scopes of a removed guest', function () {
    $membership = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    RemoveMembership::run($this->admin, $membership);

    expect($this->concierge->scopes($membership))->toHaveCount(0)
        ->and($this->concierge->allowsScoped($this->guest, $this->talk))->toBeFalse();
});

it('never transfers ownership to a scoped guest or changes their roles', function () {
    confirm_recent_authentication();
    $membership = join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    expect(fn () => TransferOwnership::run($this->owner, $membership))->toThrow(scope_denied('recipient_guest'))
        ->and(fn () => ChangeMemberRoles::run($this->owner, $membership, new GrantSet(['admin'])))->toThrow(scope_denied('guest'))
        ->and(roles_in($membership))->toBe(['guest']);
});

it('leaves scoped guests out of member lists by default and lists them on request', function () {
    join_scoped($this->workspace, $this->owner, $this->guest, $this->talk);

    $default = scoped_members_index($this->owner, $this->workspace);
    $with = scoped_members_index($this->owner, $this->workspace, guests: true);
    $guestRow = collect($with['members'])->firstWhere('user.name', 'Guest');

    $source = app(MembershipSource::class);

    expect(scoped_member_names($default))->toBe(['Admin', 'Owner'])
        ->and($default['tabs'][0]['count'])->toBe(2)
        ->and(scoped_member_names($with))->toBe(['Admin', 'Guest', 'Owner'])
        ->and($with['tabs'][0]['count'])->toBe(3)
        ->and(array_column($guestRow['actions'], 'key'))->toBe(['suspend', 'remove'])
        ->and(array_map(fn ($m) => $m->userId, $source->members($this->workspace)))->toBe([$this->owner->id, $this->admin->id])
        ->and($source)->toBeInstanceOf(ConciergeMembershipSource::class)
        ->and(array_map(fn ($m) => $m->userId, $source->includingScopedGuests()->members($this->workspace)))->toBe([$this->owner->id, $this->admin->id, $this->guest->id])
        ->and($source->member($this->workspace, $this->guest))->not->toBeNull()
        ->and(fn () => scoped_members_index($this->guest, $this->workspace, guests: true))->toThrow(scope_denied('not_member'));
});

it('keeps the scope through resend and expiry, and offers resend on a scoped invitation', function () {
    $issued = invite_scoped($this->owner, $this->workspace, $this->guest->email, $this->talk);
    $fingerprint = $issued->invitation->grants_fingerprint;

    $this->travel(2)->minutes();
    $resent = ResendInvitation::run($this->admin, $issued->invitation);

    expect($resent->invitation->scope()?->is(Scope::of($this->talk)))->toBeTrue()
        ->and($resent->invitation->grants_fingerprint)->toBe($fingerprint)
        ->and(fn () => AcceptInvitation::run($this->guest, $issued->token))->toThrow(InvitationRejected::class);

    $invitationRow = scoped_members_index($this->owner, $this->workspace, ['tab' => 'invitations'])['invitations'][0];
    expect(array_column($invitationRow['actions'], 'key'))->toContain('resend');

    $this->travel(8)->days();
    expect(fn () => AcceptInvitation::run($this->guest, $resent->token))->toThrow(InvitationRejected::class);

    // Reissuing lazily expires the stale one for the same record.
    $fresh = invite_scoped($this->owner, $this->workspace, $this->guest->email, $this->talk);
    expect($resent->invitation->fresh()->status)->toBe(InvitationStatus::Expired);

    $membership = AcceptInvitation::run($this->guest, $fresh->token)->membership;
    expect($this->concierge->scopedTo($membership, $this->talk))->toBeTrue();
});

it('refuses acceptance once the inviter can no longer grant the scoped role', function () {
    $issued = invite_scoped($this->admin, $this->workspace, $this->guest->email, $this->talk);

    config()->set('concierge.roles.guest.grantable_by', ['owner']);

    expect(InspectInvitation::run($this->guest, $issued->token)->state)->toBe(InvitationState::AuthorityLapsed);
});
