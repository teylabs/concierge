<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\AcceptTenantInvitation;
use Tey\Concierge\Actions\InspectTenantInvitation;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\Actions\ResendTenantInvitation;
use Tey\Concierge\Actions\RevokeTenantInvitation;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\TenantAcceptanceOutcome;
use Tey\Concierge\Events\TenantInvitationAccepted;
use Tey\Concierge\Events\TenantInvitationResent;
use Tey\Concierge\Events\TenantInvitationRevoked;
use Tey\Concierge\Events\TenantInvited;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Jobs\DeliverTenantInvitation;
use Tey\Concierge\Models\Ownership;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Notifications\TenantInvitationNotification;
use Workbench\App\Models\Workspace;

beforeEach(function () {
    $this->platform = host_user('platform');
    app(Concierge::class)->provisionWorkspaceUsing(
        fn ($owner, $invitation) => Workspace::query()->create(['name' => "{$owner->name}'s workspace", 'accepts_members' => true]),
    );
    host_workspace('Seed'); // creates the global role rows
});

function tenant_rejected_as(InvitationState $state): Closure
{
    return fn (InvitationRejected $e) => expect($e->state)->toBe($state);
}

function invite_tenant(string $email = 'peer@example.test')
{
    return InviteTenant::run(test()->platform, $email);
}

it('invites an email, accepts it and gives the invitee their own workspace', function () {
    $issued = invite_tenant('Peer@Example.test ');
    expect($issued->invitation->email)->toBe('peer@example.test')
        ->and($issued->invitation->status)->toBe(InvitationStatus::Pending);

    $peer = host_user('peer');
    $accepted = AcceptTenantInvitation::run($peer, $issued->token);

    expect($accepted->outcome)->toBe(TenantAcceptanceOutcome::Provisioned)
        ->and($accepted->workspace->name)->toBe("Peer's workspace")
        ->and(app(Concierge::class)->ownerOf($accepted->workspace)->user_id)->toBe($peer->id)
        ->and(roles_in($accepted->membership))->toBe(['owner'])
        ->and(Ownership::query()->whereKey($accepted->workspace->id)->exists())->toBeTrue();

    $row = $issued->invitation->fresh();
    expect($row->status)->toBe(InvitationStatus::Accepted)
        ->and($row->workspace_id)->toBe($accepted->workspace->id)
        ->and($row->accepted_by_id)->toBe($peer->id)
        ->and($row->pending_key)->toBeNull();
});

it('is idempotent for the same user and refuses anyone else afterwards', function () {
    $issued = invite_tenant();
    $peer = host_user('peer');

    $first = AcceptTenantInvitation::run($peer, $issued->token);
    $again = AcceptTenantInvitation::run($peer, $issued->token);

    expect($again->outcome)->toBe(TenantAcceptanceOutcome::AlreadyProvisioned)
        ->and($again->workspace->id)->toBe($first->workspace->id)
        ->and(Workspace::query()->where('name', "Peer's workspace")->count())->toBe(1);

    expect(fn () => AcceptTenantInvitation::run(host_user('other'), $issued->token))
        ->toThrow(InvitationRejected::class)
        ->and(fn () => AcceptTenantInvitation::run(host_user('other2'), $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::Accepted));
});

it('rejects the wrong account, an unverified account and an unknown token without writing', function () {
    $issued = invite_tenant();
    $workspaces = Workspace::query()->count();

    expect(fn () => AcceptTenantInvitation::run(host_user('stranger'), $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::WrongAccount));
    expect(fn () => AcceptTenantInvitation::run(host_user('peer', verified: false), $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::Unverified));
    expect(fn () => AcceptTenantInvitation::run(host_user('peer2'), 'nope'))
        ->toThrow(tenant_rejected_as(InvitationState::Invalid));

    expect(Workspace::query()->count())->toBe($workspaces)
        ->and($issued->invitation->fresh()->isPending())->toBeTrue();
});

it('fails closed once expired', function () {
    $issued = invite_tenant();
    $peer = host_user('peer');

    $this->travel(8)->days();

    expect(fn () => AcceptTenantInvitation::run($peer, $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::Expired));
    expect(InspectTenantInvitation::run($peer, $issued->token)->state)->toBe(InvitationState::Expired);

    expect(TenantInvitation::query()->lapsed()->count())->toBe(1)
        ->and(TenantInvitation::query()->outstanding()->count())->toBe(0);
});

it('lets an expired invitation be re-issued to the same email', function () {
    $old = invite_tenant();
    $peer = host_user('peer');
    $this->travel(8)->days();

    $new = invite_tenant();

    expect($new->invitation->id)->not->toBe($old->invitation->id)
        ->and($old->invitation->fresh()->status)->toBe(InvitationStatus::Expired);
    expect(fn () => AcceptTenantInvitation::run($peer, $old->token))
        ->toThrow(tenant_rejected_as(InvitationState::Expired));
    expect(AcceptTenantInvitation::run($peer, $new->token)->outcome)->toBe(TenantAcceptanceOutcome::Provisioned);
});

it('refuses a second pending invitation for the same email', function () {
    invite_tenant();

    expect(fn () => invite_tenant('PEER@example.test'))->toThrow(ValidationException::class);
    expect(fn () => invite_tenant('not-an-email'))->toThrow(ValidationException::class);
});

it('revokes, and a revoked invitation cannot be accepted or resent', function () {
    $issued = invite_tenant();

    $revoked = RevokeTenantInvitation::run($this->platform, $issued->invitation);
    RevokeTenantInvitation::run($this->platform, $issued->invitation); // idempotent

    expect($revoked->status)->toBe(InvitationStatus::Revoked)->and($revoked->revoked_by_id)->toBe($this->platform->id);
    expect(fn () => AcceptTenantInvitation::run(host_user('peer'), $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::Revoked));
    expect(fn () => ResendTenantInvitation::run($this->platform, $issued->invitation))
        ->toThrow(ValidationException::class);

    // the email is free to be invited again
    expect(invite_tenant()->invitation->isPending())->toBeTrue();
});

it('does not revoke an accepted invitation', function () {
    $issued = invite_tenant();
    AcceptTenantInvitation::run(host_user('peer'), $issued->token);

    expect(fn () => RevokeTenantInvitation::run($this->platform, $issued->invitation))->toThrow(ValidationException::class);
});

it('resends with a rotated token, honouring the cooldown', function () {
    $issued = invite_tenant();
    $peer = host_user('peer');

    expect(fn () => ResendTenantInvitation::run($this->platform, $issued->invitation))->toThrow(AuthorizationException::class);

    $this->travel(2)->minutes();
    $resent = ResendTenantInvitation::run($this->platform, $issued->invitation);

    expect($resent->token)->not->toBe($issued->token)->and($resent->invitation->generation)->toBe(2);
    expect(fn () => AcceptTenantInvitation::run($peer, $issued->token))
        ->toThrow(tenant_rejected_as(InvitationState::Invalid));
    expect(AcceptTenantInvitation::run($peer, $resent->token)->outcome)->toBe(TenantAcceptanceOutcome::Provisioned);
});

it('denies everyone who is not a platform owner, including workspace owners', function () {
    $issued = invite_tenant();
    $workspaceOwner = host_user('boss');
    host_workspace('Boss Co', $workspaceOwner);

    foreach ([$workspaceOwner, host_user('nobody')] as $actor) {
        expect(fn () => InviteTenant::run($actor, 'x@example.test'))->toThrow(AuthorizationException::class)
            ->and(fn () => ResendTenantInvitation::run($actor, $issued->invitation))->toThrow(AuthorizationException::class)
            ->and(fn () => RevokeTenantInvitation::run($actor, $issued->invitation))->toThrow(AuthorizationException::class);
    }

    expect(TenantInvitation::query()->count())->toBe(1);
});

it('does not trust an unverified listed email', function () {
    $impostor = host_user('platform2', verified: false);
    config(['concierge.tenant_invitations.platform_owners' => ['platform2@example.test']]);

    expect(fn () => InviteTenant::run($impostor, 'x@example.test'))->toThrow(AuthorizationException::class);
});

it('denies everyone when no platform owner is configured', function () {
    config(['concierge.tenant_invitations.platform_owners' => []]);

    expect(fn () => invite_tenant())->toThrow(AuthorizationException::class);
});

it('lets the host define the ability instead of the email list', function () {
    $delegate = host_user('delegate');
    Gate::define('concierge.manage-tenants', fn ($user) => $user->is($delegate));

    expect(InviteTenant::run($delegate, 'x@example.test')->invitation->isPending())->toBeTrue();
    expect(fn () => invite_tenant())->toThrow(AuthorizationException::class); // the listed owner no longer counts
});

it('rolls back the workspace and keeps the invitation pending when provisioning fails', function () {
    $issued = invite_tenant();
    $workspaces = Workspace::query()->count();
    app(Concierge::class)->provisionWorkspaceUsing(function () {
        Workspace::query()->create(['name' => 'Half made']);

        throw new RuntimeException('boom');
    });

    expect(fn () => AcceptTenantInvitation::run(host_user('peer'), $issued->token))->toThrow(RuntimeException::class);

    expect(Workspace::query()->count())->toBe($workspaces)->and($issued->invitation->fresh()->isPending())->toBeTrue();
});

it('requires a provisioner and a saved workspace', function () {
    $issued = invite_tenant();
    $peer = host_user('peer');

    app(Concierge::class)->provisionWorkspaceUsing(null);
    expect(fn () => AcceptTenantInvitation::run($peer, $issued->token))
        ->toThrow(UnsupportedConfiguration::class, "register a provisioner in a service provider's boot(), or set 'features.tenant_invitations' => false in config/concierge.php.");

    app(Concierge::class)->provisionWorkspaceUsing(fn () => new Workspace(['name' => 'unsaved']));
    expect(fn () => AcceptTenantInvitation::run($peer, $issued->token))
        ->toThrow(UnsupportedConfiguration::class, 'save the workspace in the closure given to Concierge::provisionWorkspaceUsing() and return it.');
});

it('inspects what the host needs for register-or-sign-in', function () {
    $issued = invite_tenant();

    expect(InspectTenantInvitation::run(null, 'nope')->state)->toBe(InvitationState::Invalid)
        ->and(InspectTenantInvitation::run(null, $issued->token)->state)->toBe(InvitationState::RegisterRequired)
        ->and(InspectTenantInvitation::run(null, $issued->token)->invitation->email)->toBe('peer@example.test');

    $peer = host_user('peer');
    expect(InspectTenantInvitation::run(null, $issued->token)->state)->toBe(InvitationState::SignInRequired)
        ->and(InspectTenantInvitation::run($peer, $issued->token)->state)->toBe(InvitationState::Ready)
        ->and(InspectTenantInvitation::run(host_user('stranger'), $issued->token)->state)->toBe(InvitationState::WrongAccount);

    AcceptTenantInvitation::run($peer, $issued->token);
    expect(InspectTenantInvitation::run($peer, $issued->token)->state)->toBe(InvitationState::AlreadyMember)
        ->and(InspectTenantInvitation::run(null, $issued->token)->state)->toBe(InvitationState::Accepted);
});

it('dispatches events that carry ids only', function () {
    Event::fake([TenantInvited::class, TenantInvitationResent::class, TenantInvitationRevoked::class, TenantInvitationAccepted::class]);

    $issued = invite_tenant();
    Event::assertDispatched(TenantInvited::class, fn ($e) => $e->invitationId === $issued->invitation->id && $e->actorId === $this->platform->id);

    $this->travel(2)->minutes();
    $resent = ResendTenantInvitation::run($this->platform, $issued->invitation);
    Event::assertDispatched(TenantInvitationResent::class, fn ($e) => $e->generation === 2);

    $peer = host_user('peer');
    $accepted = AcceptTenantInvitation::run($peer, $resent->token);
    Event::assertDispatched(TenantInvitationAccepted::class, fn ($e) => $e->workspaceId === $accepted->workspace->id
        && $e->membershipId === $accepted->membership->id && $e->userId === $peer->id && $e->outcome === 'provisioned');

    RevokeTenantInvitation::run($this->platform, invite_tenant('other@example.test')->invitation);
    Event::assertDispatched(TenantInvitationRevoked::class);
});

it('queues encrypted delivery only when the host supplies a URL', function () {
    Bus::fake([DeliverTenantInvitation::class]);

    invite_tenant('a@example.test');
    Bus::assertNotDispatched(DeliverTenantInvitation::class);

    app(Concierge::class)->tenantInvitationUrlUsing(fn ($invitation, $token) => "https://host.test/join/{$token}");
    $issued = invite_tenant('b@example.test');

    Bus::assertDispatched(DeliverTenantInvitation::class, fn (DeliverTenantInvitation $job) => $job instanceof ShouldBeEncrypted
        && $job->token === $issued->token && $job->generation === 1);
});

it('delivers with no tenant, workspace or permission team bound', function () {
    Notification::fake();
    setPermissionsTeamId(null);
    app(Concierge::class)->tenantInvitationUrlUsing(fn ($invitation, $token) => "https://host.test/join/{$token}");

    $issued = invite_tenant();

    Notification::assertSentOnDemand(TenantInvitationNotification::class, function ($n, $channels, $notifiable) use ($issued) {
        return $notifiable->routes['mail'] === 'peer@example.test'
            && $n->url === "https://host.test/join/{$issued->token}";
    });
    expect($issued->invitation->fresh()->send_count)->toBe(1);
});

it('does not deliver a stale, revoked or expired invitation', function () {
    Notification::fake();
    app(Concierge::class)->tenantInvitationUrlUsing(fn ($invitation, $token) => "https://host.test/join/{$token}");
    $issued = invite_tenant();
    Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 1);

    $this->travel(2)->minutes();
    ResendTenantInvitation::run($this->platform, $issued->invitation); // delivers generation 2
    (new DeliverTenantInvitation($issued->invitation->id, 1, $issued->token))->handle(app(Concierge::class)); // stale generation 1
    Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 2);

    RevokeTenantInvitation::run($this->platform, $issued->invitation);
    $job = new DeliverTenantInvitation($issued->invitation->id, 2, 'whatever');
    $job->handle(app(Concierge::class));
    Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 2);
});

it('defines the named limiters for the host to apply', function () {
    foreach (['concierge-tenant-invitation-inspect', 'concierge-tenant-invitation-accept', 'concierge-tenant-invitation-invite'] as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull();
    }

    $request = Request::create('/join/abc', 'POST');
    $request->setRouteResolver(fn () => (new Route('POST', '/join/{token}', []))->bind($request));
    $peer = host_user('peer');
    $request->setUserResolver(fn () => $peer);

    $limits = collect(RateLimiter::limiter(ConciergeServiceProvider::TENANT_ACCEPT_LIMITER)($request))
        ->map(fn ($limit) => explode(':', $limit->key)[0])->all();

    expect($limits)->toBe(['ip', 'token', 'user']);
});

it('reports the feature off when the switch is false', function () {
    config(['concierge.features.tenant_invitations' => false]);

    expect(ConciergeServiceProvider::tenantInvitationsEnabled())->toBeFalse();
});

it('never leaks the token hash through serialization', function () {
    $array = invite_tenant()->invitation->toArray();

    expect($array)->not->toHaveKey('token_hash')->and($array)->not->toHaveKey('pending_key');
});

it('refuses to invite an email that already has a workspace, unless allowed', function () {
    $issued = invite_tenant();
    AcceptTenantInvitation::run(host_user('peer'), $issued->token);

    expect(fn () => invite_tenant('PEER@example.test'))
        ->toThrow(ValidationException::class, 'That email already has a workspace.');
    expect(TenantInvitation::query()->count())->toBe(1);

    config(['concierge.tenant_invitations.allow_reinvite_after_accept' => true]);
    expect(invite_tenant()->invitation->isPending())->toBeTrue();
});

it('lets a revoked invitation be re-invited', function () {
    $revoked = invite_tenant();
    RevokeTenantInvitation::run($this->platform, $revoked->invitation);

    expect(invite_tenant()->invitation->isPending())->toBeTrue();
});

it('keeps the original inviter on resend and reports the resender in the event', function () {
    $issued = invite_tenant();
    $second = host_user('second');
    Gate::define('concierge.manage-tenants', fn ($user) => $user->is($this->platform) || $user->is($second));
    $this->travel(2)->minutes();
    Event::fake([TenantInvitationResent::class]);

    $resent = ResendTenantInvitation::run($second, $issued->invitation);

    expect($resent->invitation->fresh()->inviter_id)->toBe($this->platform->id);
    Event::assertDispatched(TenantInvitationResent::class, fn ($e) => $e->actorId === $second->id);
});

it('documents that a host Gate::before returning true grants tenant management', function () {
    $stranger = host_user('stranger');
    expect(fn () => InviteTenant::run($stranger, 'a@example.test'))->toThrow(AuthorizationException::class);

    Gate::before(fn ($user) => $user->is($stranger) ? true : null);
    expect(InviteTenant::run($stranger, 'a@example.test')->invitation->isPending())->toBeTrue();
});

it('does not reveal whether an account exists for tokens that cannot be accepted', function () {
    host_user('peer');
    $revoked = invite_tenant('peer@example.test');
    RevokeTenantInvitation::run($this->platform, $revoked->invitation);
    $expiring = invite_tenant('peer@example.test');
    $this->travel(8)->days();

    $states = [
        InspectTenantInvitation::run(null, 'nope')->state,
        InspectTenantInvitation::run(null, $revoked->token)->state,
        InspectTenantInvitation::run(null, $expiring->token)->state,
    ];

    expect($states)->toBe([InvitationState::Invalid, InvitationState::Revoked, InvitationState::Expired])
        ->and($states)->not->toContain(InvitationState::SignInRequired, InvitationState::RegisterRequired);
});
