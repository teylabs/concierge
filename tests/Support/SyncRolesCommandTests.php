<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Workbench\App\Models\Workspace;

it('previews missing roles without writing or clearing the cache, then reports creation and no-ops', function () {
    $registrar = app(PermissionRegistrar::class);
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Role::create(['name' => 'owner', 'guard_name' => 'api']);
    if ($registrar->teams) {
        $workspace = Workspace::create(['name' => 'Scoped roles']);
        Role::create(['name' => 'owner', 'guard_name' => 'web', $registrar->teamsKey => $workspace->id]);
    }
    $before = DB::table('roles')->orderBy('id')->get()->all();
    Cache::put($registrar->cacheKey, ['sentinel'], 600);

    expect(Artisan::call('concierge:sync-roles', ['--json' => true, '--dry-run' => true, '--no-interaction' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            ['name' => 'owner', 'guard' => 'web', 'outcome' => 'would_create'],
            ['name' => 'admin', 'guard' => 'web', 'outcome' => 'already_exists'],
            ['name' => 'presenter', 'guard' => 'web', 'outcome' => 'would_create'],
        ])
        ->and(DB::table('roles')->orderBy('id')->get()->all())->toEqual($before)
        ->and(Cache::get($registrar->cacheKey))->toBe(['sentinel']);

    expect(Artisan::call('concierge:sync-roles', ['--json' => true, '--no-interaction' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            ['name' => 'owner', 'guard' => 'web', 'outcome' => 'created'],
            ['name' => 'admin', 'guard' => 'web', 'outcome' => 'already_exists'],
            ['name' => 'presenter', 'guard' => 'web', 'outcome' => 'created'],
        ])
        ->and(Cache::has($registrar->cacheKey))->toBeFalse();

    expect(Artisan::call('concierge:sync-roles', ['--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            ['name' => 'owner', 'guard' => 'web', 'outcome' => 'already_exists'],
            ['name' => 'admin', 'guard' => 'web', 'outcome' => 'already_exists'],
            ['name' => 'presenter', 'guard' => 'web', 'outcome' => 'already_exists'],
        ])
        ->and(DB::table('roles')->count())->toBe(count($before) + 2);
});

it('reports a nonzero configuration failure and names the setting to fix', function (bool $json) {
    config()->set('permission.teams', ! config('permission.teams'));

    expect(Artisan::call('concierge:sync-roles', ['--json' => $json, '--no-interaction' => true]))->toBe(1);
    $output = Artisan::output();
    if ($json) {
        $error = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($error))->toBe(['error']);
        $output = $error['error'];
    }
    expect($output)->toContain('config/permission.php', "'teams' =>")
        ->and(DB::table('roles')->count())->toBe(0);
})->with([true, false]);

it('names migrate when the roles table is missing', function () {
    config()->set('permission.table_names.roles', 'missing_roles');

    expect(Artisan::call('concierge:sync-roles', ['--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('php artisan migrate');
});
