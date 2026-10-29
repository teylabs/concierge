<?php

use Illuminate\Support\Facades\Route;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Http\InertiaPageResponder;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Support\CurrentWorkspace;
use Tey\Concierge\Tests\Routes\RoutesTestCase;

/*
 * Every member route against the grant rules, through HTTP: an allowed actor
 * succeeds, and a stranger, a plain member, an admin acting on the owner and
 * a suspended actor are refused with nothing written. Workspace mode, Spatie
 * teams on, the current workspace taken from the session.
 */

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->acme = host_workspace('Acme', $this->owner);
    $this->alice = host_user('alice');
    $this->bob = host_user('bob');
    $this->carol = host_user('carol');
    join_as($this->acme, $this->owner, $this->alice, ['admin']);
    join_as($this->acme, $this->owner, $this->bob, ['presenter']);
    $this->carolship = join_as($this->acme, $this->owner, $this->carol, ['presenter']);
    $this->ownership = $this->owner->membershipIn($this->acme);

    // A stranger who owns another workspace: theirs is current, never Acme.
    $this->stranger = host_user('stranger');
    $this->globex = host_workspace('Globex', $this->stranger);
});

function json_as($user): RoutesTestCase
{
    return test()->actingAs($user)->withHeaders(['Accept' => 'application/json']);
}

it('shows the members list to members, with actions only for those who may take them', function () {
    $owner = json_as($this->owner)->get('/members')
        ->assertOk()
        ->assertHeader(InertiaPageResponder::HEADER, 'Concierge/Members');

    expect($owner->json('workspace.name'))->toBe('Acme')
        ->and($owner->json('metadata.links.invite'))->toBe(route('concierge.members.create'))
        ->and(collect($owner->json('members'))->firstWhere('user.email', 'carol@example.test')['links'])->toMatchArray([
            'show' => route('concierge.members.show', $this->carolship->id),
            'updateGrants' => route('concierge.members.roles', $this->carolship->id),
            'suspend' => route('concierge.members.suspend', $this->carolship->id),
            'remove' => route('concierge.members.destroy', $this->carolship->id),
            'transferOwnership' => route('concierge.members.transfer-ownership', $this->carolship->id),
        ]);

    $plain = json_as($this->bob)->get('/members')->assertOk();

    expect($plain->json('metadata.links.invite'))->toBeNull()
        ->and(collect($plain->json('members'))->pluck('actions')->flatten()->all())->toBe([]);

    $admin = json_as($this->alice)->get('/members')->assertOk();
    expect(collect($admin->json('members'))->firstWhere('user.email', 'owner@example.test')['actions'])->toBe([]);
});

it('refuses the members list to a stranger and a suspended member', function () {
    $this->actingAs(host_user('nobody'))->getJson('/members')->assertNotFound();
    // The stranger's current workspace is their own, so they never see Acme's.
    expect($this->actingAs($this->stranger)->getJson('/members')->assertOk()->json('workspace.name'))->toBe('Globex');

    SuspendMembership::run($this->owner, $this->bob->membershipIn($this->acme));
    $acme = [CurrentWorkspace::SESSION_KEY => $this->acme->id];

    $this->actingAs($this->bob)->withSession($acme)->getJson('/members')->assertForbidden();
    $this->actingAs($this->bob)->withSession($acme)->get('/members')->assertRedirect('/');
    // With nothing remembered there is no workspace left to show.
    $this->actingAs($this->bob)->withSession([])->getJson('/members')->assertNotFound();
});

it('uses the workspace remembered in the session', function () {
    join_as($this->globex, $this->stranger, $this->alice, ['presenter']);

    expect(json_as($this->alice)->get('/members')->json('workspace.name'))->toBe('Acme');

    expect($this->actingAs($this->alice)->withSession([CurrentWorkspace::SESSION_KEY => $this->globex->id])->getJson('/members')->json('workspace.name'))->toBe('Globex');
});

it('opens the invite form only for those who may invite', function () {
    $form = json_as($this->alice)->get('/members/invite')->assertOk()->assertHeader(InertiaPageResponder::HEADER, 'Concierge/InviteMember');

    expect($form->json('metadata.formUrl'))->toBe(route('concierge.members.store'))
        ->and(collect($form->json('metadata.roleOptions'))->pluck('value')->all())->toBe(['presenter'])
        ->and($form->json('metadata.links.cancel'))->toBe(route('concierge.members.index'));

    json_as($this->bob)->get('/members/invite')->assertForbidden();
    $this->actingAs(host_user('nobody'))->getJson('/members/invite')->assertNotFound();
});

it('invites through the route, and refuses who may not invite or grant', function () {
    $this->actingAs($this->alice)->post('/members/invite', ['email' => 'dave@example.test', 'roles' => ['presenter']])
        ->assertStatus(303)
        ->assertRedirect(route('concierge.members.index', ['tab' => 'invitations']));

    expect(Invitation::query()->where('email', 'dave@example.test')->where('workspace_id', $this->acme->id)->exists())->toBeTrue();

    // An admin may not grant admin; a presenter may not invite at all.
    json_as($this->alice)->post('/members/invite', ['email' => 'erin@example.test', 'roles' => ['admin']])->assertForbidden();
    json_as($this->bob)->post('/members/invite', ['email' => 'erin@example.test', 'roles' => ['presenter']])->assertForbidden();
    $this->actingAs(host_user('nobody'))->postJson('/members/invite', ['email' => 'erin@example.test', 'roles' => ['presenter']])->assertNotFound();

    expect(Invitation::query()->where('email', 'erin@example.test')->exists())->toBeFalse();
});

it('reports invite validation on the fields the page shows', function () {
    json_as($this->owner)->post('/members/invite', ['email' => 'not-an-email', 'roles' => ['wizard']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'roles.0']);

    json_as($this->owner)->post('/members/invite', ['email' => 'carol@example.test', 'roles' => ['presenter']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('shows a member page with the roles form only to someone who may change them', function () {
    $owner = json_as($this->owner)->get("/members/{$this->carolship->id}")->assertOk()->assertHeader(InertiaPageResponder::HEADER, 'Concierge/Member');

    expect($owner->json('member.user.email'))->toBe('carol@example.test')
        ->and($owner->json('grants.payload.roles'))->toBe(['presenter'])
        ->and($owner->json('grants.metadata.formUrl'))->toBe(route('concierge.members.roles', $this->carolship->id))
        ->and($owner->json('metadata.links.back'))->toBe(route('concierge.members.index'));

    expect(json_as($this->bob)->get("/members/{$this->carolship->id}")->assertOk()->json('grants'))->toBeNull();

    // Another workspace's membership is not found from here.
    json_as($this->owner)->get('/members/'.$this->stranger->membershipIn($this->globex)->id)->assertNotFound();
    json_as($this->stranger)->get("/members/{$this->carolship->id}")->assertNotFound();
});

it('changes roles for a manager, and refuses everyone else', function () {
    json_as($this->bob)->put("/members/{$this->carolship->id}/roles", ['roles' => ['admin']])->assertForbidden();
    json_as($this->alice)->put("/members/{$this->ownership->id}/roles", ['roles' => ['presenter']])->assertForbidden();
    json_as($this->alice)->put("/members/{$this->carolship->id}/roles", ['roles' => ['admin']])->assertForbidden();
    json_as($this->stranger)->put("/members/{$this->carolship->id}/roles", ['roles' => ['admin']])->assertNotFound();

    expect(roles_in($this->carolship))->toBe(['presenter'])
        ->and(roles_in($this->ownership))->toBe(['owner']);

    json_as($this->owner)->put("/members/{$this->carolship->id}/roles", ['roles' => []])->assertJsonValidationErrors(['roles']);

    $this->flushHeaders();
    $this->actingAs($this->owner)->from('/members')->put("/members/{$this->carolship->id}/roles", ['roles' => ['admin']])
        ->assertStatus(303)
        ->assertRedirect('/members');

    expect(roles_in($this->carolship))->toBe(['admin']);
});

it('suspends, reinstates and removes for a manager, and refuses everyone else', function (string $verb, string $path) {
    $call = fn ($user, $membership) => $this->actingAs($user)->{$verb.'Json'}("/members/{$membership->id}{$path}");

    if ($path === '/reinstate') {
        SuspendMembership::run($this->owner, $this->carolship);
    }

    $before = $this->carolship->fresh()->status;

    $call($this->bob, $this->carolship)->assertForbidden();
    $call($this->alice, $this->ownership)->assertForbidden();
    $call($this->alice, $this->alice->membershipIn($this->acme))->assertForbidden();
    $call($this->stranger, $this->carolship)->assertNotFound();

    expect($this->carolship->fresh()->status)->toBe($before)
        ->and($this->ownership->fresh()->status)->toBe(MembershipStatus::Active);

    $call($this->alice, $this->carolship)->assertNoContent();
    // Idempotent on retry.
    $call($this->alice, $this->carolship)->assertNoContent();

    expect($this->carolship->fresh()->status)->toBe(match ($path) {
        '/suspend' => MembershipStatus::Suspended,
        '/reinstate' => MembershipStatus::Active,
        '' => MembershipStatus::Removed,
    });
})->with([
    'suspend' => ['post', '/suspend'],
    'reinstate' => ['post', '/reinstate'],
    'remove' => ['delete', ''],
]);

it('refuses a suspended manager on every member action', function () {
    SuspendMembership::run($this->owner, $this->alice->membershipIn($this->acme));
    $this->withSession([CurrentWorkspace::SESSION_KEY => $this->acme->id]);

    json_as($this->alice)->post("/members/{$this->carolship->id}/suspend")->assertForbidden();
    json_as($this->alice)->delete("/members/{$this->carolship->id}")->assertForbidden();
    json_as($this->alice)->put("/members/{$this->carolship->id}/roles", ['roles' => ['presenter']])->assertForbidden();
    json_as($this->alice)->post('/members/invite', ['email' => 'erin@example.test', 'roles' => ['presenter']])->assertForbidden();

    expect($this->carolship->fresh()->status)->toBe(MembershipStatus::Active);
});

it('transfers ownership only for the owner, once their password is confirmed', function () {
    json_as($this->alice)->post("/members/{$this->carolship->id}/transfer-ownership")->assertForbidden();
    json_as($this->bob)->post("/members/{$this->carolship->id}/transfer-ownership")->assertForbidden();
    json_as($this->stranger)->post("/members/{$this->carolship->id}/transfer-ownership")->assertNotFound();

    // No password.confirm route in this app: the action still wants a recent confirmation.
    json_as($this->owner)->post("/members/{$this->carolship->id}/transfer-ownership")->assertForbidden();

    expect(app(Concierge::class)->ownerOf($this->acme)->id)->toBe($this->ownership->id);

    json_as($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post("/members/{$this->carolship->id}/transfer-ownership")
        ->assertNoContent();

    expect(app(Concierge::class)->ownerOf($this->acme)->id)->toBe($this->carolship->id);
});

it('sends the owner through password.confirm when the app has it', function () {
    Route::middleware('web')->get('/confirm-password', fn () => 'confirm')->name('password.confirm');
    Route::getRoutes()->refreshNameLookups();

    $this->actingAs($this->owner)->post("/members/{$this->carolship->id}/transfer-ownership")
        ->assertRedirect('/confirm-password');

    expect(app(Concierge::class)->ownerOf($this->acme)->id)->toBe($this->ownership->id);
});

it('resends and revokes invitations for a manager, and refuses everyone else', function () {
    $issued = issue($this->owner, $this->acme, 'dave@example.test');
    $id = $issued->invitation->public_id;
    $this->travel(2)->minutes();

    json_as($this->bob)->post("/members/invitations/{$id}/resend")->assertForbidden();
    json_as($this->bob)->delete("/members/invitations/{$id}")->assertForbidden();
    json_as($this->stranger)->delete("/members/invitations/{$id}")->assertNotFound();

    expect($issued->invitation->fresh()->generation)->toBe(1)
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Pending);

    json_as($this->alice)->post("/members/invitations/{$id}/resend")->assertNoContent();
    expect($issued->invitation->fresh()->generation)->toBe(2);

    // The table's row action uses PUT; both reach the same endpoint.
    $this->travel(2)->minutes();
    json_as($this->alice)->put("/members/invitations/{$id}/resend")->assertNoContent();

    json_as($this->alice)->delete("/members/invitations/{$id}")->assertNoContent();
    json_as($this->alice)->delete("/members/invitations/{$id}")->assertNoContent();
    expect($issued->invitation->fresh()->status)->toBe(InvitationStatus::Revoked);
});

it('refuses a manager resending an invitation with a role they may not grant', function () {
    $issued = issue($this->owner, $this->acme, 'dave@example.test', ['admin']);
    $this->travel(2)->minutes();

    json_as($this->alice)->post("/members/invitations/{$issued->invitation->public_id}/resend")->assertForbidden();

    expect($issued->invitation->fresh()->generation)->toBe(1);
});
