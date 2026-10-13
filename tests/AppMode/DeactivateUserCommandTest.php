<?php

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\UserDeactivated;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
    $this->presenter->forceFill(['password' => 'secret', 'remember_token' => 'remember-me'])->save();
});

/**
 * The tables DeactivateUser writes, for proving that a refusal or a dry run
 * left them exactly as they were.
 *
 * @return array<string, list<stdClass>>
 */
function deactivation_writes(): array
{
    return [
        'accounts' => DB::table('concierge_accounts')->get()->all(),
        'sessions' => DB::table('sessions')->get()->all(),
        'users' => DB::table('users')->get()->all(),
    ];
}

/** @return array<string, mixed> */
function deactivate(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:deactivate', $options))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function deactivation_refused(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:deactivate', $options))->toBe(1);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('deactivates a user as the --as user, recording who and why, and ends her sessions', function () {
    config()->set('session.driver', 'database');
    foreach ([['a', $this->presenter], ['b', $this->presenter], ['c', $this->admin]] as [$id, $user]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    $result = deactivate(['email' => $this->presenter->email, '--as' => $this->admin->email, '--reason' => 'Left the company']);

    expect($result)->toBe([
        'user' => ['id' => $this->presenter->id, 'email' => $this->presenter->email],
        'outcome' => 'deactivated',
        'sessionsEnded' => 2,
    ]);

    $account = DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole();

    expect($account->status)->toBe(AccountStatus::Deactivated->value)
        ->and($account->deactivated_by_id)->toBe($this->admin->id)
        ->and($account->deactivation_reason)->toBe('Left the company')
        ->and($account->deactivated_at)->not->toBeNull()
        ->and(app_roles($this->presenter))->toBe(['presenter'])
        ->and($this->presenter->fresh()->remember_token)->not->toBe('remember-me')->not->toBeEmpty()
        ->and(DB::table('sessions')->where('user_id', $this->presenter->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->count())->toBe(1);
});

it('reports no ended sessions when sessions are not in the database', function () {
    config()->set('session.driver', 'array');
    DB::table('sessions')->insert(['id' => 'a', 'user_id' => $this->presenter->id, 'payload' => '', 'last_activity' => time()]);

    expect(deactivate(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toMatchArray(['outcome' => 'deactivated', 'sessionsEnded' => null])
        ->and(DB::table('sessions')->where('user_id', $this->presenter->id)->count())->toBe(1);
});

it('matches both emails ignoring case and never prompts', function () {
    $this->artisan('concierge:deactivate', [
        'email' => strtoupper($this->presenter->email),
        '--as' => strtoupper($this->admin->email),
        '--reason' => '',
    ])
        ->expectsOutputToContain("{$this->presenter->email}: deactivated, no database sessions")
        ->assertSuccessful();

    expect(DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole()->status)->toBe(AccountStatus::Deactivated->value);
});

it('previews with --dry-run without writing, mailing or dispatching events', function (bool $json) {
    config()->set('session.driver', 'database');
    DB::table('sessions')->insert(['id' => 'a', 'user_id' => $this->presenter->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'c', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);
    $before = deactivation_writes();
    Event::fake([UserDeactivated::class]);

    $options = ['email' => $this->presenter->email, '--as' => $this->admin->email, '--dry-run' => true];

    if ($json) {
        expect(deactivate($options))->toBe([
            'user' => ['id' => $this->presenter->id, 'email' => $this->presenter->email],
            'outcome' => 'would_deactivate',
            'sessionsEnded' => null,
        ]);
    } else {
        expect(Artisan::call('concierge:deactivate', $options + ['--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('would deactivate, no database sessions');
    }

    expect(deactivation_writes())->toEqual($before)
        ->and(app_roles($this->presenter))->toBe(['presenter']);
    Event::assertNotDispatched(UserDeactivated::class);
})->with([true, false]);

it('refuses an unknown user, naming nothing was written', function () {
    $before = deactivation_writes();

    expect(deactivation_refused(['email' => 'ghost@example.test', '--as' => $this->admin->email]))
        ->toBe(['error' => 'No user found for [ghost@example.test].', 'code' => 'unknown_user'])
        ->and(deactivation_writes())->toEqual($before);
});

it('refuses an unknown --as user and a missing --as', function () {
    $before = deactivation_writes();

    expect(deactivation_refused(['email' => $this->presenter->email, '--as' => 'ghost@example.test']))
        ->toBe(['error' => 'No user found for [ghost@example.test]. The --as user must exist.', 'code' => 'unknown_actor']);

    expect(Artisan::call('concierge:deactivate', ['email' => $this->presenter->email, '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'concierge:deactivate needs --as=<email> naming the existing user to run the action as.',
            'code' => 'missing_actor',
        ])
        ->and(deactivation_writes())->toEqual($before);
});

it('refuses an already deactivated user', function () {
    DeactivateUser::run($this->admin, $this->presenter, reason: 'Left the company');

    expect(deactivation_refused(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'That account is already deactivated.', 'code' => 'already_deactivated'])
        ->and(DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole()->deactivation_reason)->toBe('Left the company');
});

it('refuses yourself, an equal or higher rank, and a user you cannot manage', function () {
    $other = join_app($this->owner, 'other', ['admin']);
    $before = deactivation_writes();

    expect(deactivation_refused(['email' => $this->admin->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'You cannot manage your own account.', 'code' => 'self'])
        ->and(deactivation_refused(['email' => $other->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'You cannot manage a user of equal or higher rank.', 'code' => 'rank'])
        ->and(deactivation_refused(['email' => $this->admin->email, '--as' => $this->presenter->email]))
        ->toBe(['error' => 'You cannot manage users.', 'code' => 'not_manager'])
        ->and(deactivation_writes())->toEqual($before);
});

it('refuses the last owner', function () {
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
    $before = deactivation_writes();

    expect(deactivation_refused(['email' => $second->email, '--as' => $this->owner->email]))
        ->toBe(['error' => 'The last owner cannot be removed or demoted.', 'code' => 'last_owner'])
        ->and(deactivation_writes())->toEqual($before);
});

it('honours the account guard veto', function () {
    app()->instance(AccountGuard::class, new class implements AccountGuard
    {
        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return Response::deny('Protected.', 'protected');
        }
    });
    app()->forgetInstance(AccountPolicy::class);
    app()->forgetInstance(DeactivateUser::class);

    expect(deactivation_refused(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'Protected.', 'code' => 'protected']);
});

it('refuses a deactivated actor', function () {
    DeactivateUser::run($this->owner, $this->admin);

    expect(deactivation_refused(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'Your account is not active.', 'code' => 'not_member']);
});
