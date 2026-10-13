<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Exceptions\WorkspaceNotManaged;
use Tey\Concierge\Kit\KitMembershipSource;
use Tey\Concierge\Kit\KitWorkspaceContext;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\WorkspaceMember;
use Workbench\App\Kit\Team;
use Workbench\App\Kit\TeamRole;
use Workbench\App\Kit\User;

require_once __DIR__.'/../Support/LocalAuth/requests.php';

local_login_preserve_process_environment();

function kit_user(string $name): User
{
    return User::query()->create(['name' => ucfirst($name), 'email' => "{$name}@example.test", 'email_verified_at' => now()]);
}

/** @param  array<string, TeamRole>  $members  name => role, in joining order */
function kit_team(string $name, array $members): Team
{
    $team = Team::query()->create(['name' => $name]);

    foreach ($members as $member => $role) {
        $user = User::query()->where('email', "{$member}@example.test")->first() ?? kit_user($member);
        $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        test()->travel(1)->seconds();
    }

    return $team;
}

/** @return list<array{string, list<string>, bool}> */
function described(array $members): array
{
    return array_map(fn (WorkspaceMember $m) => [User::query()->find($m->userId)->name, $m->roles, $m->owner], $members);
}

it('binds the kit adapter on the kit engine', function () {
    expect(app(MembershipSource::class))->toBeInstanceOf(KitMembershipSource::class)
        ->and(app(WorkspaceContext::class))->toBeInstanceOf(KitWorkspaceContext::class);
});

it('signs in locally without writing ownership or membership to a read-only kit team', function () {
    local_login_enable();
    $team = kit_team('Ownerless', []);

    local_login_click()->assertRedirect('/');

    $this->assertAuthenticatedAs(User::query()->where('email', LOCAL_LOGIN_EMAIL)->sole());
    expect($team->owner())->toBeNull()
        ->and(DB::table('team_members')->count())->toBe(0)
        ->and(DB::table('concierge_memberships')->count())->toBe(0)
        ->and(DB::table('concierge_ownerships')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it("reads a kit team's members and their roles from team_members", function () {
    $team = kit_team('Acme', ['olive' => TeamRole::Owner, 'adam' => TeamRole::Admin, 'mia' => TeamRole::Member]);

    $members = app(MembershipSource::class)->members($team);

    expect(described($members))->toBe([
        ['Olive', ['owner'], true],
        ['Adam', ['admin'], false],
        ['Mia', ['member'], false],
    ])->and($members[0]->workspace->is($team))->toBeTrue()
        ->and($members[0]->joinedAt)->not->toBeNull();
});

it("reads one person's kit role, and nothing for a non-member", function () {
    $team = kit_team('Acme', ['olive' => TeamRole::Owner, 'adam' => TeamRole::Admin]);
    $source = app(MembershipSource::class);

    expect($source->member($team, User::query()->where('name', 'Adam')->sole())?->roles)->toBe(['admin'])
        ->and($source->member($team, kit_user('stranger')))->toBeNull();
});

it("lists a person's kit teams with their role in each, through HasTeams", function () {
    kit_team('Personal', ['olive' => TeamRole::Owner]);
    kit_team('Acme', ['adam' => TeamRole::Owner, 'olive' => TeamRole::Member]);
    kit_team('Gone', ['olive' => TeamRole::Admin])->delete();

    $teams = app(MembershipSource::class)->workspacesOf(User::query()->where('name', 'Olive')->sole());

    expect(array_map(fn (WorkspaceMember $m) => [$m->workspace->conciergeName(), $m->roles, $m->owner], $teams))->toBe([
        ['Personal', ['owner'], true],
        ['Acme', ['member'], false],
    ]);
});

it('gives a kit team the workspace defaults from ActsAsWorkspace', function () {
    $team = Team::query()->create(['name' => 'Acme']);

    expect($team->conciergeName())->toBe('Acme')
        ->and($team->conciergeAcceptsMembers())->toBeTrue();

    $team->delete();

    expect($team->conciergeAcceptsMembers())->toBeFalse();
});

it('resolves the current team from the current_team route parameter', function () {
    $acme = kit_team('Acme', ['olive' => TeamRole::Owner]);
    kit_team('Other', ['olive' => TeamRole::Member]);
    $olive = User::query()->where('name', 'Olive')->sole();
    $olive->forceFill(['current_team_id' => Team::query()->where('name', 'Other')->value('id')])->save();

    Route::get('/{current_team}/slug', fn () => app(WorkspaceContext::class)->current()?->conciergeName());
    Route::middleware('web')->get('/{current_team}/bound', fn (Team $current_team) => app(WorkspaceContext::class)->current()?->conciergeName());

    $this->actingAs($olive)->get("/{$acme->slug}/slug")->assertSee('Acme');
    $this->actingAs($olive)->get("/{$acme->slug}/bound")->assertSee('Acme');
});

it("falls back to the user's current_team_id, and to nothing for a guest", function () {
    $acme = kit_team('Acme', ['olive' => TeamRole::Owner]);
    $olive = User::query()->where('name', 'Olive')->sole();

    Route::get('/dashboard', fn () => app(WorkspaceContext::class)->current()?->conciergeName() ?? 'none');

    $this->get('/dashboard')->assertSee('none');

    $olive->switchTeam($acme);

    $this->actingAs($olive)->get('/dashboard')->assertSee('Acme');
});

it('lets a kit member through concierge.member and refuses them after removal', function () {
    $acme = kit_team('Acme', ['olive' => TeamRole::Owner, 'mia' => TeamRole::Member]);
    $mia = User::query()->where('name', 'Mia')->sole();
    $mia->switchTeam($acme);

    Route::middleware('concierge.member')->get('/{current_team}/members', fn () => 'ok');
    Route::middleware('concierge.member')->get('/dashboard', fn () => 'ok');

    $this->actingAs($mia)->get("/{$acme->slug}/members")->assertOk()->assertSee('ok');
    $this->actingAs($mia)->get('/dashboard')->assertOk()->assertSee('ok');
    expect(DB::table('concierge_memberships')->count())->toBe(0);

    $acme->memberships()->where('user_id', $mia->id)->delete();

    $this->actingAs($mia)->get("/{$acme->slug}/members")->assertForbidden();
    $this->actingAs($mia)->get('/dashboard')->assertForbidden();
});

it('refuses a non-member of the requested kit team with concierge.member', function () {
    $acme = kit_team('Acme', ['olive' => TeamRole::Owner]);
    $other = kit_team('Other', ['stranger' => TeamRole::Member]);
    $stranger = User::query()->where('name', 'Stranger')->sole();
    $stranger->switchTeam($other);

    Route::middleware('concierge.member')->get('/{current_team}/members', fn () => 'ok');

    $this->actingAs($stranger)->get("/{$acme->slug}/members")->assertForbidden();
});

it('returns 404 without a kit workspace in concierge.member', function () {
    Route::middleware('concierge.member')->get('/dashboard', fn () => 'ok');

    $this->get('/dashboard')->assertNotFound();
    $this->actingAs(kit_user('stranger'))->get('/dashboard')->assertNotFound();
});

it('runs a callback with a team as the current one, and restores it afterwards', function () {
    $acme = Team::query()->create(['name' => 'Acme']);
    $context = app(WorkspaceContext::class);

    expect($context->run($acme, fn () => $context->current()?->conciergeName()))->toBe('Acme')
        ->and($context->current())->toBeNull()
        ->and(fn () => $context->run($acme, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and($context->current())->toBeNull();
});

it("refuses concierge's write actions on a kit team, naming the take-over issue", function (Closure $write) {
    $team = kit_team('Acme', ['olive' => TeamRole::Owner]);
    $olive = User::query()->where('name', 'Olive')->sole();

    expect(fn () => $write($olive, $team))->toThrow(WorkspaceNotManaged::class, 'teylabs/concierge#57');

    expect(app(MembershipSource::class)->members($team))->toHaveCount(1)
        ->and(DB::table('concierge_memberships')->count())->toBe(0)
        ->and(DB::table('concierge_invitations')->count())->toBe(0);
})->with([
    'invite' => [fn (User $actor, Team $team) => InviteMember::run($actor, $team, 'new@example.test', new GrantSet(['admin']))],
    'establish' => [fn (User $actor, Team $team) => EstablishWorkspace::run($team, $actor)],
]);

it('refuses to import members on the kit engine and names the fix', function () {
    $exit = Artisan::call('concierge:import-members', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($output['error'])->toContain("engine is 'kit'")
        ->and($output['fix'])->toContain("'engine' => 'concierge'");
});

it('syncs the configured roles as global Spatie roles with Spatie teams off', function () {
    expect(Artisan::call('concierge:sync-roles'))->toBe(0)
        ->and(Role::query()->orderBy('id')->pluck('name')->all())->toBe(['owner', 'admin', 'presenter']);

    Artisan::call('concierge:sync-roles');

    expect(Role::query()->count())->toBe(3);
});

it('shows the workspace engine in about', function () {
    Artisan::call('about', ['--only' => 'concierge', '--json' => true]);

    expect(json_decode(Artisan::output(), true)['concierge'])->toMatchArray(['mode' => 'workspaces', 'workspace_engine' => 'kit']);
});
