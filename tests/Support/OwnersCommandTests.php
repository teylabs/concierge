<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Support\Roles;
use Workbench\App\Models\User;

beforeEach(function () {
    Notification::fake();
    config()->set('concierge.owners', ['first@example.test']);
    config()->set('concierge.registration.mode', 'closed');

    $this->ownerWorkspace = Mode::configured() === Mode::Workspaces ? host_workspace() : null;
    $this->ownerOptions = $this->ownerWorkspace ? ['--workspace' => $this->ownerWorkspace->getKey()] : [];
});

it('creates an owner with a random hash and a usable password broker link, only once', function () {
    $this->artisan('concierge:owners', $this->ownerOptions)->assertSuccessful();

    $user = User::query()->sole();
    $password = $user->password;
    $target = $this->ownerWorkspace ? $user->membershipIn($this->ownerWorkspace) : $user;
    expect(Hash::info($password)['algoName'])->toBe('bcrypt')
        ->and(app(Concierge::class)->isOwner($target))->toBeTrue();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        expect(Password::broker()->tokenExists($user, $notification->token))->toBeTrue();

        $status = Password::reset([
            'email' => $user->email,
            'token' => $notification->token,
            'password' => 'chosen-by-owner',
            'password_confirmation' => 'chosen-by-owner',
        ], fn (User $owner, string $password) => $owner->forceFill(['password' => Hash::make($password)])->save());

        expect($status)->toBe(Password::PasswordReset);

        return true;
    });

    $attributes = $user->fresh()->getAttributes();
    Notification::fake();
    $this->artisan('concierge:owners', $this->ownerOptions)
        ->expectsOutputToContain('already owner')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->getAttributes())->toBe($attributes)
        ->and(Hash::check('chosen-by-owner', $user->fresh()->password))->toBeTrue();
    Notification::assertNothingSent();
});

it('adds ownership to an existing case-insensitive match without changing their profile or roles', function () {
    $user = User::query()->create([
        'name' => 'Existing profile',
        'email' => 'First@Example.Test',
        'email_verified_at' => null,
        'password' => Hash::make('existing-password'),
        'remember_token' => 'existing-token',
    ]);
    SyncRoleDefinitions::run();

    $grant = fn () => $user->assignRole(Roles::resolve(['admin', 'presenter']));
    if ($this->ownerWorkspace) {
        app(Concierge::class)->context()->run($this->ownerWorkspace, $grant, [$user]);
    } else {
        $grant();
    }
    $attributes = $user->fresh()->getAttributes();

    $this->artisan('concierge:owners', $this->ownerOptions)
        ->expectsOutputToContain('role added')
        ->assertSuccessful();

    $target = $this->ownerWorkspace ? $user->membershipIn($this->ownerWorkspace) : $user;
    expect(app(Concierge::class)->roleNames($target))->toBe(['owner', 'admin', 'presenter'])
        ->and($user->fresh()->getAttributes())->toBe($attributes)
        ->and(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('pins the JSON outcome when adding ownership to an existing user', function () {
    host_user('first');

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['email' => 'first@example.test', 'outcome' => 'role_added', 'linkSent' => false],
    ]);
    Notification::assertNothingSent();
});

it('never resends a link even if the newly created owner has not set a password', function () {
    $this->artisan('concierge:owners', $this->ownerOptions)->assertSuccessful();
    $user = User::query()->sole();
    $attributes = $user->getAttributes();
    $token = DB::table('password_reset_tokens')->sole();
    Notification::fake();

    $this->artisan('concierge:owners', $this->ownerOptions)->assertSuccessful();

    expect($user->fresh()->getAttributes())->toBe($attributes)
        ->and(DB::table('password_reset_tokens')->sole())->toEqual($token);
    Notification::assertNothingSent();
});

it('reports pinned JSON keys for creation and a no-op without extra output', function () {
    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['email' => 'first@example.test', 'outcome' => 'created', 'linkSent' => true],
    ]);

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['email' => 'first@example.test', 'outcome' => 'already_owner', 'linkSent' => false],
    ]);
});

it('previews creation or a role addition without writing users, roles, ownership or tokens', function (bool $existing) {
    if ($existing) {
        host_user('first');
    }
    $tables = ['users', 'roles', 'model_has_roles', 'password_reset_tokens', 'concierge_accounts'];
    if ($this->ownerWorkspace) {
        $tables = [...$tables, 'concierge_memberships', 'concierge_ownerships'];
    }
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->all()])->all();

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--dry-run' => true, '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['email' => 'first@example.test', 'outcome' => $existing ? 'would_add_role' : 'would_create', 'linkSent' => false],
    ]);
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->all()])->all())->toEqual($before);
    Notification::assertNothingSent();
})->with([false, true]);

it('rolls back a new owner when the real password broker refuses to send a link', function () {
    DB::table('password_reset_tokens')->insert([
        'email' => 'first@example.test',
        'token' => Hash::make('recent-token'),
        'created_at' => now(),
    ]);

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->not->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('rolled back')
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(DB::table('concierge_accounts')->count())->toBe(0);
    if ($this->ownerWorkspace) {
        expect(app(Concierge::class)->ownerOf($this->ownerWorkspace))->toBeNull()
            ->and(DB::table('concierge_memberships')->count())->toBe(0);
    }
    Notification::assertNothingSent();
});

it('refuses invalid configured emails before creating any owners', function () {
    config()->set('concierge.owners', ['first@example.test', 'not-an-email']);

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->not->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('CONCIERGE_OWNERS')
        ->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('does nothing successfully for an empty owner list', function () {
    config()->set('concierge.owners', []);

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([])
        ->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
