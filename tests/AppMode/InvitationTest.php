<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\InvitationAccepted;
use Tey\Concierge\Events\UserInvited;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Notifications\InvitationNotification;
use Tey\Concierge\Registration\Registration;
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
