<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Data\UI\LocalLoginOffer;
use Tey\Concierge\LocalAuth\LocalAuthServiceProvider;
use Tey\Concierge\LocalAuth\LocalLogin;
use Tey\Concierge\Models\Membership;
use Workbench\App\Models\LocalAuth\PlainAccount;
use Workbench\App\Models\LocalAuth\SecondaryAccount;
use Workbench\App\Models\LocalAuth\SoftAccount;
use Workbench\App\Models\User;

require_once __DIR__.'/../../Support/LocalAuth/requests.php';

local_login_preserve_process_environment();

/**
 * One-click local login: it signs in (and, the first time, creates) the configured
 * account in the `local` environment only, and is absent everywhere else.
 *
 * The test environment is `testing`, so nothing is registered by default.
 * `local_login_enable()` switches the app to `local` and registers the route the
 * way a real local boot would. Leaving `testing` also switches CSRF verification
 * on, which is skipped only while the environment is `testing`.
 */
it('is absent outside the local environment: no route, no offer, no account', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(Route::has(LocalLogin::ROUTE))->toBeFalse()
        ->and(app(LocalLogin::class)->offer())->toBeNull();

    $this->post('/local-login')->assertNotFound();

    expect(User::count())->toBe(0);
})->with(['testing', 'staging', 'production']);

it('refuses a route that was registered locally once the environment is no longer local', function (string $environment) {
    local_login_enable();
    app()->detectEnvironment(fn () => $environment);

    expect(app(LocalLogin::class)->offer())->toBeNull();

    local_login_click()->assertNotFound();

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with(['testing', 'staging', 'production']);

it('refuses when disabled or without an account, even in local with the route registered', function (array $config) {
    local_login_enable($config);

    expect(app(LocalLogin::class)->offer())->toBeNull();

    local_login_click()->assertNotFound();

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'disabled' => [['enabled' => false]],
    'no email' => [['account' => ['email' => null]]],
    'blank email' => [['account' => ['email' => '  ']]],
]);

it('offers a server-supplied url and the configured address', function () {
    local_login_enable();

    $offer = app(LocalLogin::class)->offer();

    expect($offer)->toBeInstanceOf(LocalLoginOffer::class)
        ->and($offer->toArray())->toBe(['url' => '/local-login', 'email' => LOCAL_LOGIN_EMAIL]);
});

it('creates the configured account and signs in without a workspace, granting nothing', function () {
    local_login_enable();

    expect(User::count())->toBe(0);

    local_login_click()->assertRedirect('/');

    $account = local_login_account();

    expect(User::count())->toBe(1)
        ->and($account->name)->toBe('Local developer')
        ->and($account->email_verified_at)->not->toBeNull()
        ->and($account->getRoleNames())->toHaveCount(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0);

    $this->assertAuthenticatedAs($account);
});

it('makes only the first local account owner of the oldest workspace', function (bool $existingAccount) {
    local_login_enable();
    $newer = host_workspace('Newer');
    $oldest = host_workspace('Oldest');
    $oldest->forceFill(['created_at' => now()->subDay()])->save();

    if ($existingAccount) {
        host_user('local');
    }

    local_login_click()->assertRedirect('/');
    $owner = local_login_account();

    $this->assertAuthenticatedAs($owner);
    expect(roles_in($owner->membershipIn($oldest)))->toBe(['owner'])
        ->and($owner->membershipIn($newer))->toBeNull();

    auth()->logout();
    config(['concierge.local_login.account.email' => 'second@example.test']);
    local_login_click()->assertRedirect('/');
    $second = User::query()->where('email', 'second@example.test')->sole();

    $this->assertAuthenticatedAs($second);
    expect($second->membershipIn($oldest))->toBeNull()
        ->and($second->membershipIn($newer))->toBeNull()
        ->and(DB::table('model_has_roles')->where('model_id', $second->id)->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(1);
})->with([false, true]);

it('grants nothing when the oldest workspace already has an owner or first owner is off', function (bool $existingOwner) {
    local_login_enable(['first_owner' => $existingOwner]);
    $workspace = host_workspace(owner: $existingOwner ? host_user('owner') : null);
    host_workspace('Another ownerless workspace');
    $before = DB::table('model_has_roles')->get()->all();

    local_login_click()->assertRedirect('/');

    $this->assertAuthenticatedAs(local_login_account());
    expect(local_login_account()->membershipIn($workspace))->toBeNull()
        ->and(DB::table('model_has_roles')->get()->all())->toEqual($before)
        ->and(Membership::query()->count())->toBe($existingOwner ? 1 : 0);
})->with([false, true]);

it('redirects to the intended url, then the configured one, then the root', function () {
    local_login_enable(['redirect' => '/configured']);

    $this->withSession(['url.intended' => '/intended'])->withSession(['_token' => 'csrf'])
        ->post(route(LocalLogin::ROUTE), ['_token' => 'csrf'])
        ->assertRedirect('/intended');

    auth()->logout();

    local_login_click()->assertRedirect('/configured');
});

it('does not touch recent-authentication state: it authenticates an identity and nothing more', function () {
    local_login_enable();

    local_login_click();

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('gives a new account a random password nobody knows', function () {
    local_login_enable();

    local_login_click();

    $hash = local_login_account()->password;

    expect($hash)->not->toBe('')
        ->and(Hash::check('password', $hash))->toBeFalse()
        ->and(Hash::check(LOCAL_LOGIN_EMAIL, $hash))->toBeFalse();

    auth()->logout();
    local_login_click();

    expect(local_login_account()->password)->toBe($hash);
});

it('reuses the account on every later click: same user, same count, same hash', function () {
    local_login_enable();

    local_login_click();
    $first = local_login_account();

    foreach (range(1, 3) as $click) {
        auth()->logout();
        local_login_click()->assertRedirect();
        $this->assertAuthenticatedAs($first);
    }

    expect(User::count())->toBe(1)
        ->and(local_login_account()->password)->toBe($first->password)
        ->and(local_login_account()->id)->toBe($first->id);
});

it('leaves an existing account exactly as it is: hash, name, verification, timestamps', function () {
    local_login_enable();

    $existing = User::query()->create([
        'email' => LOCAL_LOGIN_EMAIL,
        'name' => 'Already Here',
        'email_verified_at' => null,
        'password' => Hash::make('their own password'),
    ]);
    $before = $existing->fresh();

    local_login_click()->assertRedirect();

    $after = local_login_account();

    $this->assertAuthenticatedAs($after);
    expect(User::count())->toBe(1)
        ->and($after->password)->toBe($before->password)
        ->and($after->name)->toBe('Already Here')
        ->and($after->email_verified_at)->toBeNull()
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(Hash::check('their own password', $after->password))->toBeTrue();
});

it('survives two first clicks racing for the same new account', function () {
    local_login_enable();

    // Simulate the other request winning in the gap between our lookup (which found
    // nothing) and our insert: the winner row appears right after that lookup runs.
    // It must not be written from a model event, which fires inside our own insert
    // transaction and would be rolled back with it.
    $raced = false;
    DB::listen(function ($query) use (&$raced): void {
        if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! in_array(LOCAL_LOGIN_EMAIL, $query->bindings, true)) {
            return;
        }

        $raced = true;
        DB::table('users')->insert(['name' => 'Winner', 'email' => LOCAL_LOGIN_EMAIL, 'password' => 'winner-hash', 'created_at' => now(), 'updated_at' => now()]);
    });

    local_login_click()->assertRedirect('/');

    expect($raced)->toBeTrue()
        ->and(User::count())->toBe(1)
        ->and(local_login_account()->name)->toBe('Winner')
        ->and(local_login_account()->password)->toBe('winner-hash');

    $this->assertAuthenticatedAs(local_login_account());
});

it('never trusts the browser: a submitted identity or password is ignored', function () {
    local_login_enable();

    local_login_click(['email' => 'someone.else@example.com', 'password' => 'whatever', 'name' => 'Mallory'])->assertRedirect();

    $this->assertAuthenticatedAs(local_login_account());
    expect(User::count())->toBe(1)
        ->and(User::where('email', 'someone.else@example.com')->exists())->toBeFalse();
});

it('lets creation attributes fill extra columns but never the identity or the password', function () {
    local_login_enable(['account' => ['name' => 'Ignored name', 'attributes' => [
        'name' => 'From attributes',
        'email' => 'evil@example.com',
        'password' => Hash::make('known'),
    ]]]);

    local_login_click();

    $account = local_login_account();

    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('From attributes')
        ->and(User::where('email', 'evil@example.com')->exists())->toBeFalse()
        ->and(Hash::check('known', $account->password))->toBeFalse();
});

it('refuses a soft-deleted account without restoring it or creating a second', function () {
    Schema::create('soft_accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
        $table->softDeletes();
    });
    config([
        'auth.providers.soft' => ['driver' => 'eloquent', 'model' => SoftAccount::class],
        'auth.guards.soft' => ['driver' => 'session', 'provider' => 'soft'],
    ]);
    local_login_enable(['guard' => 'soft']);

    SoftAccount::query()->create(['email' => LOCAL_LOGIN_EMAIL, 'password' => 'x'])->delete();

    $this->from('/login')->withSession(['_token' => 'csrf'])
        ->post(route(LocalLogin::ROUTE), ['_token' => 'csrf'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('local_login');

    expect(session('errors')->first('local_login'))->toContain('deleted');
    $this->assertGuest('soft');
    expect(SoftAccount::withTrashed()->count())->toBe(1)
        ->and(SoftAccount::withTrashed()->first()->trashed())->toBeTrue();
});

it('fails closed with a useful message for a guard that is not an Eloquent provider', function () {
    config([
        'auth.providers.raw' => ['driver' => 'database', 'table' => 'users'],
        'auth.guards.raw' => ['driver' => 'session', 'provider' => 'raw'],
    ]);
    local_login_enable(['guard' => 'raw']);

    $this->from('/login')->withSession(['_token' => 'csrf'])
        ->post(route(LocalLogin::ROUTE), ['_token' => 'csrf'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('local_login');

    expect(session('errors')->first('local_login'))->toContain('Eloquent');
    $this->assertGuest('raw');
    expect(DB::table('users')->count())->toBe(0);
});

it('works for a model without soft deletes, on a configured guard', function () {
    Schema::create('plain_accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
    config([
        'auth.providers.plain' => ['driver' => 'eloquent', 'model' => PlainAccount::class],
        'auth.guards.plain' => ['driver' => 'session', 'provider' => 'plain'],
    ]);
    local_login_enable(['guard' => 'plain', 'redirect' => '/plain-home']);
    host_workspace();

    local_login_click()->assertRedirect('/plain-home');

    expect(PlainAccount::count())->toBe(1)
        ->and(User::count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
    $this->assertAuthenticated('plain');
    expect(auth('plain')->user()->email)->toBe(LOCAL_LOGIN_EMAIL);
});

it('denies a visitor already signed in on the configured, non-default guard', function () {
    Schema::create('plain_accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
    config([
        'auth.providers.plain' => ['driver' => 'eloquent', 'model' => PlainAccount::class],
        'auth.guards.plain' => ['driver' => 'session', 'provider' => 'plain'],
    ]);
    local_login_enable(['guard' => 'plain']);

    $other = PlainAccount::query()->create(['email' => 'other@example.test', 'password' => 'x']);

    // A real session on the non-default guard. actingAs() would also make that guard
    // the default one, which would let a bare `guest` middleware see it too.
    $this->withSession([auth()->guard('plain')->getName() => $other->getKey(), '_token' => 'csrf'])
        ->post(route(LocalLogin::ROUTE), ['_token' => 'csrf'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($other, 'plain');
    expect(PlainAccount::query()->where('email', LOCAL_LOGIN_EMAIL)->exists())->toBeFalse();
});

it('reads the schema from the model connection, not the default one', function () {
    config(['database.connections.secondary' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    // The default connection has no `secondary_accounts` table at all.
    Schema::connection('secondary')->create('secondary_accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->timestamps();
    });
    config([
        'auth.providers.secondary' => ['driver' => 'eloquent', 'model' => SecondaryAccount::class],
        'auth.guards.secondary' => ['driver' => 'session', 'provider' => 'secondary'],
    ]);
    local_login_enable(['guard' => 'secondary', 'account' => ['name' => null]]);

    local_login_click()->assertRedirect();

    $account = SecondaryAccount::query()->first();

    expect(Schema::hasTable('secondary_accounts'))->toBeFalse()
        ->and($account->name)->toBe('Local')
        ->and($account->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($account, 'secondary');
});

it('rotates the session id and the csrf token on sign-in and sets no remember token', function () {
    local_login_enable();

    $this->withSession(['_token' => 'csrf']);
    $sessionBefore = session()->getId();

    $response = $this->post(route(LocalLogin::ROUTE), ['_token' => 'csrf']);

    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(session()->token())->not->toBe('csrf')
        ->and(local_login_account()->remember_token)->toBeNull();

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toStartWith('remember_');
    }
});

it('requires the csrf token: a forged cross-site post cannot sign anyone in', function () {
    local_login_enable();

    $this->post(route(LocalLogin::ROUTE))->assertStatus(419);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('does not switch a signed-in visitor to the local account', function () {
    local_login_enable();
    $other = host_user('other');

    $this->actingAs($other)->withSession(['_token' => 'csrf'])
        ->post(route(LocalLogin::ROUTE), ['_token' => 'csrf'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($other);
    expect(User::where('email', LOCAL_LOGIN_EMAIL)->exists())->toBeFalse();
});

it('throttles repeated clicks', function () {
    local_login_enable(['throttle' => 2]);

    foreach (range(1, 2) as $click) {
        local_login_click()->assertRedirect();
        auth()->logout();
    }

    local_login_click()->assertTooManyRequests();
});

it('denies with a 404 before csrf, guest or throttle checks run, in every non-local environment', function (string $environment) {
    local_login_enable();
    app()->detectEnvironment(fn () => $environment);

    // No csrf token on purpose: if csrf verification ran first this would be a 419.
    $this->post(route(LocalLogin::ROUTE))->assertNotFound();

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with(['testing', 'staging', 'production']);

it('defines the rate limiter in every environment, so a locally cached route cannot trip over a missing one', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(RateLimiter::limiter(LocalAuthServiceProvider::LIMITER))->not->toBeNull();
})->with(['testing', 'staging', 'production']);

it('requires the exact raw local environment on every offer and request', function (?string $environment) {
    local_login_enable();
    local_login_process_environment($environment);

    expect(app()->environment())->toBe('local')
        ->and(app(LocalLogin::class)->offer())->toBeNull();
    $this->post('/local-login')->assertNotFound();
    local_login_click()->assertNotFound();
    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with(['production', 'staging', 'testing', null, '', 'LOCAL', ' local', 'local ']);

it('refuses direct route registration outside either local boundary', function (string $application, ?string $process) {
    app()->detectEnvironment(fn () => $application);
    local_login_process_environment($process);
    config(['concierge.local_login.enabled' => true, 'concierge.local_login.account.email' => LOCAL_LOGIN_EMAIL]);

    app()->getProvider(LocalAuthServiceProvider::class)->registerRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has(LocalLogin::ROUTE))->toBeFalse()
        ->and(app(LocalLogin::class)->isAvailable())->toBeFalse();
})->with([
    ['local', 'production'], ['local', null], ['production', 'local'], ['testing', 'local'],
]);

it('uses server fallback only when the process value is absent and strictly local', function (?string $process, mixed $server, bool $available) {
    local_login_enable();
    local_login_process_environment($process);
    $_SERVER['APP_ENV'] = $server;

    expect(app(LocalLogin::class)->isAvailable())->toBe($available);
})->with([
    'server local' => [null, 'local', true],
    'process wins' => ['production', 'local', false],
    'empty process wins' => ['', 'local', false],
    'local process wins' => ['local', 'production', true],
    'server absent' => [null, null, false],
    'server case' => [null, 'LOCAL', false],
    'server whitespace' => [null, 'local ', false],
    'server integer' => [null, 0, false],
    'server boolean' => [null, true, false],
    'server array' => [null, ['local'], false],
]);

it('denies a raw environment switch before an exhausted throttle', function () {
    local_login_enable(['throttle' => 1]);
    local_login_click()->assertRedirect();
    auth()->logout();
    local_login_process_environment('production');

    local_login_click()->assertNotFound();
    $this->assertGuest();
    expect(User::count())->toBe(1);
});

it('denies missing raw environment before the signed-in guest redirect', function () {
    local_login_enable();
    $other = host_user('other');
    local_login_process_environment(null);

    $this->actingAs($other)->post('/local-login')->assertNotFound();
    $this->assertAuthenticatedAs($other);
    expect(local_login_account())->toBeNull();
});
