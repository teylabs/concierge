<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Tests\Setup\Fixtures\PlainUser;
use Workbench\App\Models\Organisation;
use Workbench\App\Models\Workspace;

function install(array $options = []): array
{
    Artisan::call('concierge:install', ['--no-interaction' => true, '--json' => true] + $options);

    return json_decode(Artisan::output(), true);
}

function statuses(array $result): array
{
    return collect($result['steps'])->mapWithKeys(fn (array $step) => [$step['step'] => $step['status']])->all();
}

it('sets a fresh app up app-wide with no questions', function () {
    $result = install();

    expect($result['set_up'])->toBeTrue()
        ->and($result['choices']['mode'])->toBe('app')
        ->and(statuses($result))->toBe([
            'permission-config' => 'done',
            'permission-teams' => 'done',
            'permission-migrations' => 'done',
            'concierge-config' => 'done',
            'user-model' => 'already',
            'migrate' => 'done',
            'roles' => 'done',
            'first-owner' => 'manual',
            'frontend' => 'info',
        ])
        ->and(file_get_contents(config_path('concierge.php')))->toContain("'mode' => 'app'")->toContain("'user' => \\Workbench\\App\\Models\\User::class")
        ->and(file_get_contents(config_path('permission.php')))->toContain("'teams' => false")
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('concierge_invitations'))->toBeTrue()
        ->and(DB::table('roles')->pluck('name')->all())->toContain('owner');
});

it('turns concierge routes on for a fresh install', function () {
    install(['--workspace-model' => Workspace::class]);

    $config = require config_path('concierge.php');

    expect($config['routes']['enabled'])->toBeTrue()
        ->and($config['routes']['middleware'])->toBe(['web', 'auth'])
        ->and(config('concierge.routes.enabled'))->toBeTrue()
        ->and(Route::has('concierge.members.index'))->toBeTrue()
        ->and(Route::has('concierge.invitations.show'))->toBeTrue();
});

it('changes nothing on a second run', function () {
    install();
    $files = [config_path('concierge.php') => md5_file(config_path('concierge.php')), config_path('permission.php') => md5_file(config_path('permission.php'))];
    $migrations = glob(database_path('migrations/*.php'));

    $result = install();

    expect(collect(statuses($result))->except(['first-owner', 'frontend'])->unique()->values()->all())->toBe(['already'])
        ->and(array_map('md5_file', array_keys($files)))->toBe(array_values($files))
        ->and(glob(database_path('migrations/*.php')))->toBe($migrations);
});

it('sets up workspace mode with Spatie teams on', function () {
    $result = install(['--workspace-model' => Workspace::class]);

    expect($result['set_up'])->toBeTrue()
        ->and($result['choices'])->toMatchArray(['mode' => 'workspaces', 'engine' => 'concierge', 'workspace_model' => Workspace::class])
        ->and(file_get_contents(config_path('permission.php')))->toContain("'teams' => true")
        ->and(Schema::hasTable('concierge_memberships'))->toBeTrue()
        ->and(Schema::hasColumn('roles', 'workspace_id') || Schema::hasColumn('roles', 'team_id'))->toBeTrue();
});

it('adopts an existing organisation model without losing its rows', function () {
    $this->artisan('migrate', ['--force' => true]);
    Schema::create('organisations', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('owner_id')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    DB::table('organisations')->insert(['name' => 'Acme']);

    $result = install(['--workspace-model' => Organisation::class]);

    expect($result['set_up'])->toBeTrue()
        ->and(DB::table('organisations')->pluck('name')->all())->toBe(['Acme'])
        ->and(Schema::hasTable('concierge_memberships'))->toBeTrue();
});

it('plans without changing anything on a dry run', function () {
    $result = install(['--dry-run' => true]);

    expect($result['set_up'])->toBeFalse()
        ->and(collect(statuses($result))->except(['user-model', 'first-owner', 'frontend'])->unique()->values()->all())->toBe(['would'])
        ->and(file_exists(config_path('concierge.php')))->toBeFalse()
        ->and(file_exists(config_path('permission.php')))->toBeFalse()
        ->and(Schema::hasTable('roles'))->toBeFalse();
});

it('pins the json keys', function () {
    $result = install(['--dry-run' => true]);

    expect(array_keys($result))->toBe(['detected', 'choices', 'steps', 'set_up'])
        ->and(array_keys($result['detected']))->toBe(['starter_kit', 'authkit', 'kit_teams', 'sign_in', 'user_model', 'user_key', 'team_model', 'permission', 'concierge'])
        ->and(array_keys($result['detected']['permission']))->toBe(['config_published', 'migrations_published', 'tables', 'teams'])
        ->and(array_keys($result['detected']['concierge']))->toBe(['config_published'])
        ->and(array_keys($result['choices']))->toBe(['mode', 'engine', 'user_model', 'workspace_model', 'local_login'])
        ->and(array_keys($result['steps'][0]))->toBe(['step', 'status', 'detail']);
});

it('says what to add to a user model without the contract, and stops before migrating', function () {
    config()->set('auth.providers.users.model', PlainUser::class);

    $this->artisan('concierge:install', ['--no-interaction' => true])
        ->expectsOutputToContain('user-model: manual')
        ->expectsOutputToContain("Add Member to the class's implements list")
        ->expectsOutputToContain('migrate: blocked')
        ->assertFailed();

    expect(Schema::hasTable('concierge_invitations'))->toBeFalse();
});

it('refuses an unknown mode', function () {
    $this->artisan('concierge:install', ['--no-interaction' => true, '--mode' => 'teams'])
        ->expectsOutputToContain('--mode must be app or workspaces')
        ->assertFailed();
});

it('refuses a workspace model that does not exist', function () {
    $this->artisan('concierge:install', ['--no-interaction' => true, '--workspace-model' => 'App\Models\Missing'])
        ->expectsOutputToContain('pass --workspace-model')
        ->assertFailed();
});

it('asks whether to adopt the team model it found', function () {
    $this->artisan('concierge:install', ['--dry-run' => true])
        ->expectsConfirmation('Use '.Organisation::class." as concierge's workspaces?", 'yes')
        ->expectsConfirmation('No sign-in was found. Turn on local login for development?', 'no')
        ->expectsOutputToContain('concierge-config: would')
        ->assertSuccessful();
});

it('detects each starter kit', function (array $composer, array $npm, string $kit, bool $authkit, ?string $signIn) {
    $this->appFile('composer.json', ['require' => $composer]);
    $this->appFile('package.json', ['dependencies' => $npm]);

    $detected = install(['--dry-run' => true])['detected'];

    expect($detected['starter_kit'])->toBe($kit)
        ->and($detected['authkit'])->toBe($authkit)
        ->and($detected['sign_in'])->toBe($signIn);
})->with([
    'vue' => [['laravel/fortify' => '^1'], ['@inertiajs/vue3' => '^2'], 'vue', false, 'fortify'],
    'react' => [['laravel/fortify' => '^1'], ['@inertiajs/react' => '^2'], 'react', false, 'fortify'],
    'svelte' => [['laravel/fortify' => '^1'], ['@inertiajs/svelte' => '^2'], 'svelte', false, 'fortify'],
    'livewire' => [['laravel/fortify' => '^1', 'livewire/flux' => '^2'], [], 'livewire', false, 'fortify'],
    'vue with WorkOS AuthKit' => [['laravel/workos' => '^0.1'], ['@inertiajs/vue3' => '^2'], 'vue', true, 'workos'],
    'none' => [[], [], 'none', false, null],
]);
