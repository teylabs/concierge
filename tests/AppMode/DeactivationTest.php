<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\SanctumServiceProvider;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Actions\RestoreUser;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\UserDeactivated;
use Tey\Concierge\Events\UserRestored;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\User;

function deactivation_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

function account_of(User $user): Account
{
    return Account::query()->findOrFail($user->id);
}

beforeEach(function () {
    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
    $this->presenter->forceFill(['password' => Hash::make('secret'), 'remember_token' => 'remember-me'])->save();
});

it('deactivates a user, records who and why, and keeps their roles', function () {
    Event::fake([UserDeactivated::class]);

    $account = DeactivateUser::run($this->admin, $this->presenter, reason: '  Left the company ');

    expect($account->status)->toBe(AccountStatus::Deactivated)
        ->and($account->deactivated_at)->not->toBeNull()
        ->and($account->deactivated_by_id)->toBe($this->admin->id)
        ->and($account->deactivation_reason)->toBe('Left the company')
        ->and(app_roles($this->presenter))->toBe(['presenter'])
        ->and(app(Concierge::class)->account($this->presenter)->isActive())->toBeFalse();

    Event::assertDispatched(UserDeactivated::class, fn ($e) => $e->userId === $this->presenter->id
        && $e->actorId === $this->admin->id
        && $e->reason === 'Left the company');
});

it('cycles the remember token and ends database sessions', function () {
    config()->set('session.driver', 'database');

    foreach ([[$this->presenter, 'a'], [$this->presenter, 'b'], [$this->admin, 'c']] as [$user, $id]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    DeactivateUser::run($this->admin, $this->presenter);

    expect($this->presenter->fresh()->remember_token)->not->toBe('remember-me')->not->toBeEmpty()
        ->and(DB::table('sessions')->where('user_id', $this->presenter->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->count())->toBe(1);
});

it('reports no ended sessions when sessions are not in the database', function () {
    Event::fake([UserDeactivated::class]);

    DeactivateUser::run($this->admin, $this->presenter);

    Event::assertDispatched(UserDeactivated::class, fn ($e) => $e->sessionsEnded === null);
});

it('restores a user with their roles and clears the deactivation record', function () {
    Event::fake([UserRestored::class]);

    DeactivateUser::run($this->admin, $this->presenter, reason: 'Left');
    $account = RestoreUser::run($this->admin, $this->presenter);

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->deactivated_at)->toBeNull()
        ->and($account->deactivated_by_id)->toBeNull()
        ->and($account->deactivation_reason)->toBeNull()
        ->and(app_roles($this->presenter))->toBe(['presenter']);

    Event::assertDispatched(UserRestored::class, fn ($e) => $e->userId === $this->presenter->id && $e->actorId === $this->admin->id);
});

it('refuses to deactivate yourself', function () {
    expect(fn () => DeactivateUser::run($this->admin, $this->admin))->toThrow(deactivation_denied('self'));
    expect(fn () => RestoreUser::run($this->admin, $this->admin))->toThrow(deactivation_denied('self'));
});

it('refuses someone of equal or higher rank', function () {
    $other = join_app($this->owner, 'other', ['admin']);

    expect(fn () => DeactivateUser::run($this->admin, $other))->toThrow(deactivation_denied('rank'))
        ->and(fn () => DeactivateUser::run($this->admin, $this->owner))->toThrow(deactivation_denied('rank'))
        ->and(fn () => DeactivateUser::run($this->presenter, $this->admin))->toThrow(deactivation_denied('not_manager'))
        ->and(account_of($this->owner)->isActive())->toBeTrue();
});

it('refuses a deactivated actor', function () {
    DeactivateUser::run($this->owner, $this->admin);

    expect(fn () => DeactivateUser::run($this->admin, $this->presenter))->toThrow(deactivation_denied('not_member'));
});

it('lets an owner deactivate and restore a co-owner', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    DeactivateUser::run($this->owner, $second);

    expect(account_of($second)->isActive())->toBeFalse()
        ->and(app_roles($second))->toBe(['owner']);

    RestoreUser::run($this->owner, $second);
    expect(account_of($second)->isActive())->toBeTrue();
});

it('refuses the last owner: a co-owner the account guard shields does not count', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    // A guard that shields the actor from management: the only other owner is not one the actor could replace.
    app()->instance(AccountGuard::class, new class($this->owner) implements AccountGuard
    {
        public function __construct(private User $shielded) {}

        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return $target->getKey() === $this->shielded->getKey() ? Response::deny('Protected.', 'protected') : Response::allow();
        }
    });
    app()->forgetInstance(AccountPolicy::class);
    app()->forgetInstance(DeactivateUser::class);

    expect(fn () => DeactivateUser::run($this->owner, $second))->toThrow(deactivation_denied('last_owner'))
        ->and(account_of($second)->isActive())->toBeTrue();
});

it('honours an account guard veto', function () {
    app()->instance(AccountGuard::class, new class implements AccountGuard
    {
        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return Response::deny('Protected.', 'protected');
        }
    });
    app()->forgetInstance(AccountPolicy::class);
    app()->forgetInstance(DeactivateUser::class);

    expect(fn () => DeactivateUser::run($this->admin, $this->presenter))->toThrow(deactivation_denied('protected'));
});

it('refuses deactivating twice and restoring an active user', function () {
    expect(fn () => RestoreUser::run($this->admin, $this->presenter))->toThrow(deactivation_denied('not_deactivated'));

    DeactivateUser::run($this->admin, $this->presenter);

    expect(fn () => DeactivateUser::run($this->admin, $this->presenter))->toThrow(deactivation_denied('already_deactivated'));
});

it('truncates a long reason to the column length', function () {
    $account = DeactivateUser::run($this->admin, $this->presenter, reason: str_repeat('é', 600));

    expect(mb_strlen($account->deactivation_reason))->toBe(500);
});

it('refuses sign-in once the credentials check out, on the email key', function () {
    DeactivateUser::run($this->admin, $this->presenter);

    $attempt = fn () => Auth::guard('web')->attempt(['email' => $this->presenter->email, 'password' => 'secret']);

    expect($attempt)->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['email' => ['This account is deactivated.']]));
    $this->assertGuest();

    RestoreUser::run($this->admin, $this->presenter);

    expect($attempt())->toBeTrue();
    $this->assertAuthenticatedAs($this->presenter);
});

it('does not reveal a deactivated account to a wrong password', function () {
    DeactivateUser::run($this->admin, $this->presenter);

    expect(Auth::guard('web')->attempt(['email' => $this->presenter->email, 'password' => 'wrong']))->toBeFalse();
});

it('signs out a session on the next request, and lets it back in after restore', function () {
    Route::middleware(['web', 'auth', 'concierge.active'])->get('/_dashboard', fn () => 'ok');
    Route::get('/login', fn () => 'login')->name('login');

    $this->actingAs($this->presenter)->get('/_dashboard')->assertOk();

    DeactivateUser::run($this->admin, $this->presenter);

    $this->actingAs($this->presenter)->get('/_dashboard')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'This account is deactivated.']);
    $this->assertGuest();

    RestoreUser::run($this->admin, $this->presenter);

    $this->actingAs($this->presenter)->get('/_dashboard')->assertOk();
});

it('answers a JSON request from a deactivated user with a 403', function () {
    Route::middleware(['web', 'auth', 'concierge.active'])->get('/_api', fn () => 'ok');

    DeactivateUser::run($this->admin, $this->presenter);

    $this->actingAs($this->presenter)->getJson('/_api')->assertForbidden()->assertJsonPath('message', 'This account is deactivated.');
});

it('blocks a Sanctum token while deactivated without deleting it, and works again after restore', function () {
    app()->register(SanctumServiceProvider::class);
    Schema::create('personal_access_tokens', function ($table) {
        $table->id();
        $table->morphs('tokenable');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
        $table->timestamps();
    });
    Route::middleware(['auth:sanctum', 'concierge.active'])->get('/_token', fn () => 'ok');

    $token = $this->presenter->createToken('cli')->plainTextToken;
    $call = fn () => $this->withToken($token)->getJson('/_token');

    $call()->assertOk();

    DeactivateUser::run($this->admin, $this->presenter);
    app('auth')->forgetGuards();

    $call()->assertForbidden();
    expect($this->presenter->tokens()->count())->toBe(1);

    RestoreUser::run($this->admin, $this->presenter);
    app('auth')->forgetGuards();

    $call()->assertOk();
});
