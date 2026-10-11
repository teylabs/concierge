<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\Organisation;
use Workbench\App\Models\User;

/** @param  array<string, ?string>  $members  name => pivot role, in joining order */
function organisation(string $name, array $members, ?User $ownerColumn = null): Organisation
{
    $organisation = Organisation::query()->create(['name' => $name, 'owner_id' => $ownerColumn?->id]);

    foreach ($members as $member => $role) {
        $user = User::query()->where('email', "{$member}@example.test")->first() ?? host_user($member);
        DB::table('organisation_user')->insert([
            'organisation_id' => $organisation->id,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        test()->travel(1)->seconds();
    }

    return $organisation;
}

/** @return array{int, array<string, mixed>} */
function import_members(array $options = []): array
{
    $exit = Artisan::call('concierge:import-members', ['--json' => true] + $options);

    return [$exit, json_decode(Artisan::output(), true)];
}

function owner_name(Organisation $organisation): ?string
{
    return app(Concierge::class)->ownerOf($organisation)?->user->name;
}

/** @return array<string, list<string>> name => concierge roles */
function imported_roles(Organisation $organisation): array
{
    return Membership::query()->where('workspace_id', $organisation->id)->with('user')->get()
        ->mapWithKeys(fn (Membership $m) => [$m->user->name => roles_in($m)])
        ->sortKeys()
        ->all();
}

it('plans the import without writing anything on a dry run', function () {
    organisation('Acme', ['olive' => 'owner', 'adam' => 'admin', 'pia' => 'presenter']);

    [$exit, $plan] = import_members(['--role-column' => 'role', '--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and($plan['dry_run'])->toBeTrue()
        ->and($plan['pivot'])->toBe('organisation_user')
        ->and($plan['nothing_to_do'])->toBeFalse()
        ->and($plan['summary'])->toBe(['workspaces' => 1, 'owners' => 1, 'members' => 2, 'skipped' => 0])
        ->and($plan['workspaces'][0]['owner'])->toMatchArray(['email' => 'olive@example.test', 'source' => 'role', 'action' => 'establish'])
        ->and(array_column($plan['workspaces'][0]['members'], 'role', 'email'))->toBe(['adam@example.test' => 'admin', 'pia@example.test' => 'presenter'])
        ->and(Membership::query()->count())->toBe(0)
        ->and(DB::table('concierge_ownerships')->count())->toBe(0);
});

it('imports members and the owner, leaves the pivot alone, and has nothing to do the second time', function () {
    $acme = organisation('Acme', ['olive' => 'owner', 'adam' => 'admin', 'mia' => 'member']);
    $pivot = DB::table('organisation_user')->orderBy('id')->get()->toArray();

    [$exit, $result] = import_members(['--role-column' => 'role', '--map' => ['member:presenter']]);

    expect($exit)->toBe(0)
        ->and($result['dry_run'])->toBeFalse()
        ->and(owner_name($acme))->toBe('Olive')
        ->and(imported_roles($acme))->toBe(['Adam' => ['admin'], 'Mia' => ['presenter'], 'Olive' => ['owner']])
        ->and(Membership::query()->pluck('status')->unique()->all())->toBe([MembershipStatus::Active])
        ->and(DB::table('organisation_user')->orderBy('id')->get()->toArray())->toEqual($pivot);

    [$exit, $again] = import_members(['--role-column' => 'role', '--map' => ['member:presenter']]);

    expect($exit)->toBe(0)
        ->and($again['nothing_to_do'])->toBeTrue()
        ->and($again['summary'])->toBe(['workspaces' => 1, 'owners' => 0, 'members' => 0, 'skipped' => 3])
        ->and($again['workspaces'][0]['owner'])->toMatchArray(['email' => 'olive@example.test', 'source' => 'existing', 'action' => 'keep'])
        ->and(Membership::query()->count())->toBe(3);

    Artisan::call('concierge:import-members', ['--role-column' => 'role', '--map' => ['member:presenter']]);

    expect(Artisan::output())->toContain('Nothing to import from [organisation_user]');
});

it('picks the owner from the role column, then the owner column, then the earliest member', function () {
    $zed = host_user('zed');
    $byRole = organisation('By role', ['ann' => 'admin', 'bob' => 'owner'], ownerColumn: $zed);
    $byColumn = organisation('By column', ['cat' => 'admin', 'zed' => 'admin'], ownerColumn: $zed);
    $outside = organisation('Owner outside the pivot', ['dan' => 'admin'], ownerColumn: $zed);
    $earliest = organisation('Earliest', ['eve' => 'admin', 'fay' => 'admin']);

    [, $plan] = import_members(['--role-column' => 'role', '--dry-run' => true]);

    expect(array_map(fn ($w) => [$w['name'], $w['owner']['email'], $w['owner']['source']], $plan['workspaces']))->toBe([
        ['By role', 'bob@example.test', 'role'],
        ['By column', 'zed@example.test', 'owner_column'],
        ['Owner outside the pivot', 'zed@example.test', 'owner_column'],
        ['Earliest', 'eve@example.test', 'earliest'],
    ]);

    import_members(['--role-column' => 'role']);

    expect(owner_name($byRole))->toBe('Bob')
        ->and(owner_name($byColumn))->toBe('Zed')
        ->and(imported_roles($outside))->toBe(['Dan' => ['admin'], 'Zed' => ['owner']])
        ->and(owner_name($earliest))->toBe('Eve');
});

it('gives every member the default role without a role column', function () {
    $acme = organisation('Acme', ['olive' => null, 'adam' => null]);

    import_members(['--default-role' => 'presenter']);

    expect(owner_name($acme))->toBe('Olive')
        ->and(imported_roles($acme))->toBe(['Adam' => ['presenter'], 'Olive' => ['owner']]);
});

it('gives a second owner in the pivot the rank below owner', function () {
    $twin = organisation('Twin owners', ['ann' => 'owner', 'bob' => 'owner']);

    import_members(['--role-column' => 'role']);

    expect(imported_roles($twin))->toBe(['Ann' => ['owner'], 'Bob' => ['admin']]);
});

it('leaves people who already have a concierge membership as they are', function () {
    $acme = organisation('Acme', ['olive' => 'owner', 'adam' => 'admin']);
    $olive = User::query()->where('name', 'Olive')->sole();
    $adam = User::query()->where('name', 'Adam')->sole();
    SyncRoleDefinitions::run();
    EstablishWorkspace::run($acme, $olive);
    AcceptInvitation::run($adam, InviteMember::run($olive, $acme, $adam->email, new GrantSet(['presenter']))->token);
    SuspendMembership::run($olive, $adam->membershipIn($acme));

    [, $result] = import_members(['--role-column' => 'role']);

    expect($result['nothing_to_do'])->toBeTrue()
        ->and($result['workspaces'][0]['members'])->toBe([
            ['user_id' => $olive->id, 'email' => 'olive@example.test', 'source_role' => 'owner', 'role' => null, 'action' => 'skip', 'reason' => 'already a member (active)'],
            ['user_id' => $adam->id, 'email' => 'adam@example.test', 'source_role' => 'admin', 'role' => null, 'action' => 'skip', 'reason' => 'already a member (suspended)'],
        ])
        ->and($adam->membershipIn($acme)->status)->toBe(MembershipStatus::Suspended)
        ->and(roles_in($adam->membershipIn($acme)))->toBe(['presenter']);
});

it('refuses role values with no concierge role, writing nothing, and names the fix', function () {
    organisation('Acme', ['olive' => 'owner', 'adam' => 'editor', 'mia' => 'viewer']);

    [$exit, $error] = import_members(['--role-column' => 'role']);

    expect($exit)->toBe(1)
        ->and($error['error'])->toBe('No concierge role for the role-column value(s) [editor], [viewer].')
        ->and($error['fix'])->toContain('--map=<value>:<concierge role>')
        ->and(Membership::query()->count())->toBe(0);
});

it('refuses, naming the fix, when the input does not fit', function (array $options, string $error, string $fix) {
    organisation('Acme', ['olive' => 'owner']);

    [$exit, $output] = import_members($options);

    expect($exit)->toBe(1)
        ->and($output['error'])->toContain($error)
        ->and($output['fix'])->toContain($fix);
})->with([
    'another model' => [['--model' => User::class], 'is not concierge.models.workspace', "'models' => ['workspace' =>"],
    'missing pivot' => [['--pivot' => 'team_user'], 'The pivot table [team_user] does not exist.', '--pivot=<table>'],
    'missing column' => [['--role-column' => 'rank'], 'has no [rank] column', 'organisation_id, user_id, role'],
    'bad map' => [['--map' => ['member']], '--map=member is not from:to.', '--map=member:presenter'],
    'unknown role' => [['--default-role' => 'editor'], '[editor] is not a concierge role.', 'owner, admin, presenter'],
    'mapping to owner' => [['--map' => ['admin:owner']], '[owner] cannot be mapped to', 'admin'],
]);

it('refuses in app-wide mode and without Spatie teams', function (string $key, mixed $value, string $fix) {
    config()->set($key, $value);

    [$exit, $output] = import_members();

    expect($exit)->toBe(1)->and($output['fix'])->toContain($fix);
})->with([
    'app-wide' => ['concierge.mode', 'app', "'mode' => 'workspaces'"],
    'teams off' => ['permission.teams', false, "'teams' => true"],
]);

it('prints a readable plan without --json', function () {
    organisation('Acme', ['olive' => 'owner', 'adam' => 'admin']);

    $exit = Artisan::call('concierge:import-members', ['--role-column' => 'role', '--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Acme', 'owner olive@example.test (from role)', 'adam@example.test', 'Would import 1 member(s) and 1 owner(s) into 1 workspace(s)');
});
