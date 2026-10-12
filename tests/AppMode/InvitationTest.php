<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\InvitationAccepted;
use Tey\Concierge\Events\InvitationResent;
use Tey\Concierge\Events\InvitationRevoked;
use Tey\Concierge\Events\UserInvited;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Notifications\InvitationNotification;
use Tey\Concierge\Registration\Registration;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConfiguredAccountPolicy;
use Tey\Concierge\Support\GrantSet;

function app_rejected_as(InvitationState $state): Closure
{
    return fn (InvitationRejected $e) => expect($e->state)->toBe($state);
}

beforeEach(function () {
    $this->owner = app_owner();
});

it('issues invitations with no workspace and one pending per email', function () {
    Event::fake([UserInvited::class]);

    $issued = invite_user($this->owner, 'New.Person@Example.test');

    expect($issued->invitation->workspace_id)->toBeNull()
        ->and($issued->invitation->isAppWide())->toBeTrue()
        ->and($issued->invitation->email)->toBe('new.person@example.test')
        ->and($issued->invitation->token_hash)->toBe(hash('sha256', $issued->token))
        ->and(fn () => invite_user($this->owner, 'new.person@example.test'))->toThrow(ValidationException::class)
        ->and(fn () => invite_user($this->owner, 'not-an-email'))->toThrow(ValidationException::class);

    Event::assertDispatched(UserInvited::class, fn (UserInvited $e) => $e->invitationId === $issued->invitation->id && $e->actorId === $this->owner->id);

    $this->travel(8)->days();
    $second = invite_user($this->owner, 'new.person@example.test');

    expect($issued->invitation->fresh()->status)->toBe(InvitationStatus::Expired)
        ->and($issued->invitation->fresh()->pending_key)->toBeNull()
        ->and($second->invitation->isPending())->toBeTrue();
});

it('does not invite an email that already has an account', function () {
    $alice = host_user('alice');

    expect(fn () => invite_user($this->owner, $alice->email))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['email'][0])->toBe('That person already has an account; change their roles instead.'));

    Account::query()->create(['user_id' => $alice->id, 'status' => AccountStatus::Deactivated]);

    expect(fn () => invite_user($this->owner, $alice->email))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['email'][0])->toBe('That account is deactivated; restore it instead.'));
});

it('grants the roles to the account on acceptance and is idempotent', function () {
    Event::fake([InvitationAccepted::class]);
    $issued = invite_user($this->owner, 'alice@example.test', ['admin']);
    $alice = host_user('alice');

    $acceptance = AcceptInvitation::run($alice, $issued->token);

    expect($acceptance->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and($acceptance->membership)->toBeNull()
        ->and($acceptance->account->user_id)->toBe($alice->id)
        ->and($acceptance->account->invitation_id)->toBe($issued->invitation->id)
        ->and($acceptance->account->joined_at)->not->toBeNull()
        ->and(app_roles($alice))->toBe(['admin'])
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and($issued->invitation->fresh()->accepted_by_id)->toBe($alice->id)
        ->and($issued->invitation->fresh()->pending_key)->toBeNull();

    Event::assertDispatched(InvitationAccepted::class, fn (InvitationAccepted $e) => $e->workspaceId === null
        && $e->membershipId === null
        && $e->invitationId === $issued->invitation->id
        && $e->userId === $alice->id
        && $e->outcome === 'joined');

    expect(AcceptInvitation::run($alice, $issued->token)->outcome)->toBe(AcceptanceOutcome::AlreadyMember)
        ->and(fn () => AcceptInvitation::run(host_user('mallory'), $issued->token))->toThrow(app_rejected_as(InvitationState::Accepted));
});

it('rejects the wrong account, unverified accounts and deactivated accounts without writing', function () {
    $issued = invite_user($this->owner, 'alice@example.test');

    expect(fn () => AcceptInvitation::run(host_user('mallory'), $issued->token))->toThrow(app_rejected_as(InvitationState::WrongAccount));

    $alice = host_user('alice', verified: false);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(app_rejected_as(InvitationState::Unverified))
        ->and(Account::query()->find($alice->id))->toBeNull()
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending);

    $alice->forceFill(['email_verified_at' => now()])->save();
    Account::query()->create(['user_id' => $alice->id, 'status' => AccountStatus::Deactivated]);

    expect(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(app_rejected_as(InvitationState::Suspended))
        ->and(app_roles($alice))->toBe([]);
});

it('rejects when the inviter lost the authority to grant', function () {
    $admin = join_app($this->owner, 'admin', ['admin']);
    $issued = invite_user($admin, 'alice@example.test');
    $alice = host_user('alice');

    expect(InspectInvitation::run($alice, $issued->token)->state)->toBe(InvitationState::Ready);

    ChangeUserRoles::run($this->owner, $admin, new GrantSet(['presenter']));

    expect(InspectInvitation::run($alice, $issued->token)->state)->toBe(InvitationState::AuthorityLapsed)
        ->and(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(app_rejected_as(InvitationState::AuthorityLapsed));
});

it('resends under the current manager with a new token, expiry and grant snapshot', function () {
    $this->freezeSecond();
    Notification::fake();
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/invitations/{$token}");
    $admin = join_app($this->owner, 'admin', ['admin']);
    $issued = invite_user($this->owner, 'alice@example.test');
    $alice = host_user('alice');
    Role::findByName('presenter')->givePermissionTo(Permission::create(['name' => 'slides.edit', 'guard_name' => 'web']));
    Event::fake([InvitationResent::class]);
    $this->travel(2)->days();

    $resent = DB::transaction(function () use ($admin, $issued) {
        $resent = ResendInvitation::run($admin, $issued->invitation);
        Event::assertNotDispatched(InvitationResent::class);

        return $resent;
    });

    expect($resent->invitation->id)->toBe($issued->invitation->id)
        ->and($resent->invitation->workspace_id)->toBeNull()
        ->and($resent->invitation->inviter_id)->toBe($admin->id)
        ->and($resent->invitation->generation)->toBe(2)
        ->and($resent->invitation->token_hash)->toBe(hash('sha256', $resent->token))
        ->and($resent->token)->not->toBe($issued->token)
        ->and($resent->invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($resent->invitation->grants['roles']['presenter']['permissions'])->toBe(['slides.edit'])
        ->and($resent->invitation->grants_fingerprint)->not->toBe($issued->invitation->grants_fingerprint)
        ->and(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(app_rejected_as(InvitationState::Invalid))
        ->and(AcceptInvitation::run($alice, $resent->token)->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and(app_roles($alice))->toBe(['presenter']);

    Event::assertDispatchedTimes(InvitationResent::class, 1);
    Event::assertDispatched(InvitationResent::class, fn (InvitationResent $e) => $e->workspaceId === null
        && $e->invitationId === $issued->invitation->id && $e->generation === 2 && $e->actorId === $admin->id);
    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->url === "https://host.test/invitations/{$resent->token}");
});

it('revokes an app-wide invitation idempotently and frees its pending email', function () {
    $this->freezeSecond();
    $admin = join_app($this->owner, 'admin', ['admin']);
    $issued = invite_user($this->owner, 'alice@example.test', ['admin']);
    $alice = host_user('alice');
    Event::fake([InvitationRevoked::class]);

    $revoked = DB::transaction(function () use ($admin, $issued) {
        $revoked = RevokeInvitation::run($admin, $issued->invitation);
        Event::assertNotDispatched(InvitationRevoked::class);

        return $revoked;
    });

    expect($revoked->status)->toBe(InvitationStatus::Revoked)
        ->and($revoked->pending_key)->toBeNull()
        ->and($revoked->revoked_by_id)->toBe($admin->id)
        ->and($revoked->revoked_at->equalTo(now()))->toBeTrue()
        ->and(fn () => AcceptInvitation::run($alice, $issued->token))->toThrow(app_rejected_as(InvitationState::Revoked))
        ->and(fn () => ResendInvitation::run($this->owner, $issued->invitation))->toThrow(ValidationException::class)
        ->and(RevokeInvitation::run($this->owner, $issued->invitation)->revoked_by_id)->toBe($admin->id);

    Event::assertDispatchedTimes(InvitationRevoked::class, 1);
    Event::assertDispatched(InvitationRevoked::class, fn (InvitationRevoked $e) => $e->workspaceId === null
        && $e->invitationId === $issued->invitation->id && $e->actorId === $admin->id);

    $alice->delete();
    expect(invite_user($this->owner, 'alice@example.test')->invitation->isPending())->toBeTrue();
});

it('enforces the resend cooldown after issuance and rotation', function () {
    $this->freezeSecond();
    $issued = invite_user($this->owner, 'alice@example.test');
    $cooldown = fn (AuthorizationException $e) => expect($e->getCode())->toBe('cooldown');

    expect(fn () => ResendInvitation::run($this->owner, $issued->invitation))->toThrow($cooldown);

    $this->travel(60)->seconds();
    $resent = ResendInvitation::run($this->owner, $issued->invitation);

    expect(fn () => ResendInvitation::run($this->owner, $issued->invitation))->toThrow($cooldown);

    $this->travel(60)->seconds();
    expect(ResendInvitation::run($this->owner, $resent->invitation)->invitation->generation)->toBe(3);
});

it('denies non-managers and deactivated managers without changing the invitation', function (string $action) {
    $issued = invite_user($this->owner, 'alice@example.test');
    $stranger = host_user('stranger');
    Event::fake([InvitationResent::class, InvitationRevoked::class]);
    $this->travel(2)->minutes();
    $before = $issued->invitation->fresh()->getAttributes();

    expect(fn () => $action::run($stranger, $issued->invitation))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('not_manager'));

    Account::query()->whereKey($this->owner->id)->update(['status' => AccountStatus::Deactivated->value]);

    expect(fn () => $action::run($this->owner, $issued->invitation))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('not_member'))
        ->and($issued->invitation->fresh()->getAttributes())->toBe($before)
        ->and(Account::query()->find($stranger->id))->toBeNull();

    Event::assertNotDispatched(InvitationResent::class);
    Event::assertNotDispatched(InvitationRevoked::class);
})->with([ResendInvitation::class, RevokeInvitation::class]);

it('requires authority to grant every role when resending', function () {
    $admin = join_app($this->owner, 'admin', ['admin']);
    $issued = invite_user($this->owner, 'alice@example.test', ['admin']);
    $this->travel(2)->minutes();

    expect(fn () => ResendInvitation::run($admin, $issued->invitation))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('not_grantable'))
        ->and($issued->invitation->fresh()->generation)->toBe(1)
        ->and($issued->invitation->fresh()->token_hash)->toBe(hash('sha256', $issued->token));
});

it('honours a host AccountPolicy veto', function (string $action) {
    $issued = invite_user($this->owner, 'alice@example.test');
    $this->travel(2)->minutes();
    app()->instance(AccountPolicy::class, new class(app(AppScope::class), app(AccountGuard::class)) extends ConfiguredAccountPolicy
    {
        public function authorizeManagement(Member $actor, ?Member $target = null): Response
        {
            return Response::deny('Invitations are locked.', 'invitations_locked');
        }
    });

    expect(fn () => $action::run($this->owner, $issued->invitation))
        ->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('invitations_locked'))
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and($issued->invitation->fresh()->generation)->toBe(1);
})->with([ResendInvitation::class, RevokeInvitation::class]);

it('refuses to resend or revoke an accepted invitation even with a stale model', function (string $action) {
    $issued = invite_user($this->owner, 'alice@example.test');
    AcceptInvitation::run(host_user('alice'), $issued->token);
    $this->travel(2)->minutes();

    expect(fn () => $action::run($this->owner, $issued->invitation))->toThrow(ValidationException::class)
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and($issued->invitation->fresh()->generation)->toBe(1);
})->with([ResendInvitation::class, RevokeInvitation::class]);

it('renews an elapsed pending invitation but not one already marked expired', function () {
    $issued = invite_user($this->owner, 'alice@example.test');
    $this->travel(8)->days();

    $resent = ResendInvitation::run($this->owner, $issued->invitation);

    expect($resent->invitation->isPending())->toBeTrue()
        ->and($resent->invitation->isExpired())->toBeFalse();

    $this->travel(8)->days();
    invite_user($this->owner, 'alice@example.test');

    expect(fn () => ResendInvitation::run($this->owner, $issued->invitation))->toThrow(ValidationException::class)
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Expired)
        ->and(RevokeInvitation::run($this->owner, $issued->invitation)->status)->toBe(InvitationStatus::Revoked);
});

it('revokes an elapsed pending invitation', function () {
    $issued = invite_user($this->owner, 'alice@example.test');
    $this->travel(8)->days();

    expect(RevokeInvitation::run($this->owner, $issued->invitation)->status)->toBe(InvitationStatus::Revoked);
});

it('inspects app-wide invitations for the acceptance page', function () {
    $issued = invite_user($this->owner, 'alice@example.test');

    expect(InspectInvitation::run(null, $issued->token)->state)->toBe(InvitationState::RegisterRequired)
        ->and(InspectInvitation::run(null, 'nope')->state)->toBe(InvitationState::Invalid)
        ->and(InspectInvitation::run(host_user('mallory'), $issued->token)->state)->toBe(InvitationState::WrongAccount);

    $alice = host_user('alice');

    expect(InspectInvitation::run(null, $issued->token)->state)->toBe(InvitationState::SignInRequired)
        ->and(InspectInvitation::run($alice, $issued->token)->state)->toBe(InvitationState::Ready)
        ->and(InspectInvitation::run($alice, $issued->token)->invitation->isAppWide())->toBeTrue();

    AcceptInvitation::run($alice, $issued->token);

    expect(InspectInvitation::run($alice, $issued->token)->state)->toBe(InvitationState::AlreadyMember)
        ->and(InspectInvitation::run(host_user('eve'), $issued->token)->state)->toBe(InvitationState::Accepted);
});

it('delivers with the app name in place of a workspace name', function () {
    Notification::fake();
    config()->set('app.name', 'Concierge Test App');
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/invitations/{$token}");

    $issued = invite_user($this->owner, 'a@example.test');

    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->workspaceName === 'Concierge Test App'
        && $n->url === "https://host.test/invitations/{$issued->token}");
});

it('lets the registration gate honour an app-wide invitation', function () {
    config()->set('concierge.registration.mode', 'invitation');
    $issued = invite_user($this->owner, 'alice@example.test');

    expect(Registration::decide('alice@example.test', $issued->token)->allowed)->toBeTrue()
        ->and(Registration::decide('bob@example.test', $issued->token)->allowed)->toBeFalse();
});

it('guards routes on an active account rather than a workspace', function () {
    Route::middleware('concierge.member')->get('/probe', fn () => 'ok');
    $alice = join_app($this->owner, 'alice');
    $stranger = host_user('stranger');

    $this->get('/probe')->assertForbidden();
    $this->actingAs($alice)->get('/probe')->assertOk();
    $this->actingAs($stranger)->get('/probe')->assertOk();

    Account::query()->whereKey($alice->id)->update(['status' => AccountStatus::Deactivated->value]);

    $this->actingAs($alice)->get('/probe')->assertForbidden();
    expect(DB::table('concierge_accounts')->where('user_id', $stranger->id)->exists())->toBeFalse();
});
