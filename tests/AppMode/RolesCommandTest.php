<?php

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\RemoveUser;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserRolesChanged;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\User;

/** A host guard shielding one account from everyone, as #49's operator guard will. */
function roles_command_shield(User $operator): void
{
    app()->instance(AccountGuard::class, new class($operator->id) implements AccountGuard
    {
        public function __construct(private int $operatorId) {}

        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return (int) $target->getKey() === $this->operatorId
                ? Response::deny('The operator account cannot be managed.', 'operator')
                : Response::allow();
        }
    });
}

beforeEach(function () {
    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
});

it('changes a user\'s roles as the --as user, with one or several --role', function () {
    Event::fake([UserRolesChanged::class]);

    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['admin'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'user' => 'presenter@example.test',
            'outcome' => 'changed',
            'roles' => ['admin'],
        ])
        ->and(app_roles($this->presenter))->toBe(['admin']);

    Event::assertDispatched(UserRolesChanged::class, fn (UserRolesChanged $e) => $e->userId === $this->presenter->id
        && $e->roles === ['admin']
        && $e->actorId === $this->owner->id);

    // Repeating --role sets several; --as matches case-insensitively.
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['presenter', 'admin'],
        '--as' => 'OWNER@example.test',
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'user' => 'presenter@example.test',
            'outcome' => 'changed',
            'roles' => ['admin', 'presenter'],
        ])
        ->and(app_roles($this->presenter))->toBe(['admin', 'presenter']);
});

it('reports an unchanged set and still runs the action', function () {
    Event::fake([UserRolesChanged::class]);

    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['presenter'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'user' => 'presenter@example.test',
            'outcome' => 'unchanged',
            'roles' => ['presenter'],
        ])
        ->and(app_roles($this->presenter))->toBe(['presenter']);

    // The command is the same action with a named actor, so its events are untouched.
    Event::assertDispatched(UserRolesChanged::class, fn (UserRolesChanged $e) => $e->userId === $this->presenter->id);
});

it('prints a one-line text outcome without --json', function () {
    $this->artisan('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['admin'],
        '--as' => 'owner@example.test',
    ])->expectsOutputToContain('presenter@example.test: changed; roles: admin')->assertSuccessful();
});

it('previews without writing or dispatching, and still authorizes', function () {
    Event::fake([UserRolesChanged::class]);
    $tables = ['users', 'roles', 'model_has_roles', 'concierge_accounts'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->all()])->all();

    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['admin'],
        '--as' => 'owner@example.test',
        '--dry-run' => true,
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'user' => 'presenter@example.test',
            'outcome' => 'would_change',
            'roles' => ['admin'],
        ]);

    // A dry-run of the current set reports unchanged.
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['presenter'],
        '--as' => 'owner@example.test',
        '--dry-run' => true,
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['outcome'])->toBe('unchanged');

    // The action's authorization check still runs: an admin may not grant admin.
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['admin'],
        '--as' => 'admin@example.test',
        '--dry-run' => true,
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'You cannot grant the [admin] role.',
            'code' => 'not_grantable',
        ]);

    expect(collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->all()])->all())->toEqual($before);
    Event::assertNotDispatched(UserRolesChanged::class);
});

it('refuses a missing or unknown --as, naming the option', function (?string $as, string $code) {
    $options = [
        'email' => 'presenter@example.test',
        '--role' => ['presenter'],
        '--json' => true,
    ];
    if ($as !== null) {
        $options['--as'] = $as;
    }

    expect(Artisan::call('concierge:roles', $options))->toBe(1);

    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['code'])->toBe($code)
        ->and($error['error'])->toContain('--as')
        ->and(app_roles($this->presenter))->toBe(['presenter']);
})->with([
    'missing' => [null, 'missing_actor'],
    'blank' => ['  ', 'missing_actor'],
    'unknown' => ['nobody@example.test', 'unknown_actor'],
]);

it('refuses an unknown user', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'ghost@example.test',
        '--role' => ['presenter'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'No user found for [ghost@example.test].',
            'code' => 'unknown_user',
        ]);
});

it('requires at least one --role', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1);

    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['code'])->toBe('missing_roles')
        ->and($error['error'])->toContain('concierge:roles needs at least one --role=<role>');
});

it('refuses an unknown role, naming the fix', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['super-admin'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1);

    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['code'])->toBe('unknown_role')
        ->and($error['error'])->toContain('[super-admin] is not a concierge role', 'config/concierge.php')
        ->and(app_roles($this->presenter))->toBe(['presenter']);
});

it('refuses a configured role that is not synced as unsupported_configuration', function () {
    config()->set('concierge.roles.mega', ['label' => 'Mega', 'grantable_by' => ['owner']]);

    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['mega'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1);

    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['code'])->toBe('unsupported_configuration')
        ->and($error['error'])->toContain('Role [mega] is not defined', 'php artisan concierge:sync-roles')
        ->and(app_roles($this->presenter))->toBe(['presenter']);
});

it('refuses a role the actor may not grant, in JSON and in text', function (bool $json) {
    expect(Artisan::call('concierge:roles', [
        'email' => 'presenter@example.test',
        '--role' => ['admin'],
        '--as' => 'admin@example.test',
        '--json' => $json,
    ]))->toBe(1);

    $output = Artisan::output();
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'You cannot grant the [admin] role.',
            'code' => 'not_grantable',
        ]);
    } else {
        expect($output)->toContain('You cannot grant the [admin] role.');
    }
    expect(app_roles($this->presenter))->toBe(['presenter']);
})->with([true, false]);

it('refuses to change your own roles', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'owner@example.test',
        '--role' => ['admin'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'You cannot manage your own account.',
            'code' => 'self',
        ])
        ->and(app_roles($this->owner))->toBe(['owner']);
});

it('refuses a target of equal or higher rank', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'owner@example.test',
        '--role' => ['presenter'],
        '--as' => 'admin@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'You cannot manage a user of equal or higher rank.',
            'code' => 'rank',
        ])
        ->and(app_roles($this->owner))->toBe(['owner']);
});

it('refuses an actor who may not manage users', function () {
    expect(Artisan::call('concierge:roles', [
        'email' => 'admin@example.test',
        '--role' => ['presenter'],
        '--as' => 'presenter@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'You cannot manage users.',
            'code' => 'not_manager',
        ])
        ->and(app_roles($this->admin))->toBe(['admin']);
});

it('refuses to demote the last owner', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));
    RemoveUser::run($second, $this->owner);
    $operator = app_owner('operator');
    roles_command_shield($operator);

    expect(Artisan::call('concierge:roles', [
        'email' => 'second@example.test',
        '--role' => ['admin'],
        '--as' => 'operator@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'The last owner cannot be removed or demoted.',
            'code' => 'last_owner',
        ])
        ->and(app_roles($second))->toBe(['owner']);
});

it('honours the host AccountGuard veto', function () {
    $operator = app_owner('operator');
    roles_command_shield($operator);

    expect(Artisan::call('concierge:roles', [
        'email' => 'operator@example.test',
        '--role' => ['admin'],
        '--as' => 'owner@example.test',
        '--json' => true,
    ]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'The operator account cannot be managed.',
            'code' => 'operator',
        ])
        ->and(app_roles($operator))->toBe(['owner']);
});
