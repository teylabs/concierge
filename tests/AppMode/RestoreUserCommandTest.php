<?php

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Actions\RestoreUser;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\UserRestored;

beforeEach(function () {
    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
    DeactivateUser::run($this->admin, $this->presenter, reason: 'Left the company');
});

/**
 * The tables RestoreUser writes, for proving that a refusal or a dry run left
 * them exactly as they were.
 *
 * @return array<string, list<stdClass>>
 */
function restoration_writes(): array
{
    return [
        'accounts' => DB::table('concierge_accounts')->get()->all(),
        'roles' => DB::table('model_has_roles')->get()->all(),
        'users' => DB::table('users')->get()->all(),
    ];
}

/** @return array<string, mixed> */
function restore(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:restore', $options))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function restoration_refused(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:restore', $options))->toBe(1);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('restores a deactivated user as the --as user, with the roles she had', function () {
    Event::fake([UserRestored::class]);

    expect(restore(['email' => $this->presenter->email, '--as' => $this->admin->email]))->toBe([
        'user' => ['id' => $this->presenter->id, 'email' => $this->presenter->email],
        'outcome' => 'restored',
    ]);

    $account = DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole();

    expect($account->status)->toBe(AccountStatus::Active->value)
        ->and($account->deactivated_at)->toBeNull()
        ->and($account->deactivated_by_id)->toBeNull()
        ->and($account->deactivation_reason)->toBeNull()
        ->and(app_roles($this->presenter))->toBe(['presenter']);
    Event::assertDispatched(UserRestored::class, fn (UserRestored $event) => $event->userId === $this->presenter->id);
});

it('matches both emails ignoring case and never prompts', function () {
    $this->artisan('concierge:restore', [
        'email' => strtoupper($this->presenter->email),
        '--as' => strtoupper($this->admin->email),
    ])
        ->expectsOutputToContain("{$this->presenter->email}: restored")
        ->assertSuccessful();

    expect(DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole()->status)->toBe(AccountStatus::Active->value);
});

it('previews with --dry-run without writing or dispatching events', function (bool $json) {
    $before = restoration_writes();
    Event::fake([UserRestored::class]);

    $options = ['email' => $this->presenter->email, '--as' => $this->admin->email, '--dry-run' => true];

    if ($json) {
        expect(restore($options))->toBe([
            'user' => ['id' => $this->presenter->id, 'email' => $this->presenter->email],
            'outcome' => 'would_restore',
        ]);
    } else {
        expect(Artisan::call('concierge:restore', $options + ['--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain("{$this->presenter->email}: would restore");
    }

    expect(restoration_writes())->toEqual($before)
        ->and(DB::table('concierge_accounts')->where('user_id', $this->presenter->id)->sole()->status)->toBe(AccountStatus::Deactivated->value);
    Event::assertNotDispatched(UserRestored::class);
})->with([true, false]);

it('refuses an unknown user and a missing email', function () {
    $before = restoration_writes();

    expect(restoration_refused(['email' => 'ghost@example.test', '--as' => $this->admin->email]))
        ->toBe(['error' => 'No user found for [ghost@example.test].', 'code' => 'unknown_user'])
        ->and(restoration_refused(['email' => ' ', '--as' => $this->admin->email]))
        ->toBe(['error' => 'concierge:restore needs the email of the user to restore.', 'code' => 'missing_user'])
        ->and(restoration_writes())->toEqual($before);
});

it('refuses an unknown --as user and a missing --as', function () {
    $before = restoration_writes();

    expect(restoration_refused(['email' => $this->presenter->email, '--as' => 'ghost@example.test']))
        ->toBe(['error' => 'No user found for [ghost@example.test]. The --as user must exist.', 'code' => 'unknown_actor']);

    expect(Artisan::call('concierge:restore', ['email' => $this->presenter->email, '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'concierge:restore needs --as=<email> naming the existing user to run the action as.',
            'code' => 'missing_actor',
        ])
        ->and(restoration_writes())->toEqual($before);
});

it('refuses an account that is not deactivated', function () {
    $active = join_app($this->owner, 'active', ['presenter']);
    $before = restoration_writes();

    expect(restoration_refused(['email' => $active->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'That account is not deactivated.', 'code' => 'not_deactivated'])
        ->and(restoration_writes())->toEqual($before);
});

it('refuses yourself, an equal or higher rank, and a user you cannot manage', function () {
    $other = join_app($this->owner, 'other', ['admin']);
    DeactivateUser::run($this->owner, $other);
    $viewer = join_app($this->owner, 'viewer', ['presenter']);
    $before = restoration_writes();

    expect(restoration_refused(['email' => $this->admin->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'You cannot manage your own account.', 'code' => 'self'])
        ->and(restoration_refused(['email' => $other->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'You cannot manage a user of equal or higher rank.', 'code' => 'rank'])
        ->and(restoration_refused(['email' => $this->presenter->email, '--as' => $viewer->email]))
        ->toBe(['error' => 'You cannot manage users.', 'code' => 'not_manager'])
        ->and(restoration_writes())->toEqual($before);
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
    app()->forgetInstance(RestoreUser::class);

    expect(restoration_refused(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'Protected.', 'code' => 'protected']);
});

it('refuses a deactivated actor', function () {
    DeactivateUser::run($this->owner, $this->admin);

    expect(restoration_refused(['email' => $this->presenter->email, '--as' => $this->admin->email]))
        ->toBe(['error' => 'Your account is not active.', 'code' => 'not_member']);
});

it('refuses on a dry run exactly what the real run refuses', function (string $scenario) {
    [$email, $as] = match ($scenario) {
        'unknown user' => ['ghost@example.test', $this->admin->email],
        'unknown actor' => [$this->presenter->email, 'ghost@example.test'],
        'not deactivated' => [join_app($this->owner, 'active', ['presenter'])->email, $this->admin->email],
        'self' => [$this->admin->email, $this->admin->email],
        'rank' => [tap(join_app($this->owner, 'other', ['admin']), fn ($other) => DeactivateUser::run($this->owner, $other))->email, $this->admin->email],
        'not manager' => [$this->presenter->email, join_app($this->owner, 'viewer', ['presenter'])->email],
        'deactivated actor' => [$this->presenter->email, tap($this->admin, fn ($admin) => DeactivateUser::run($this->owner, $admin))->email],
    };
    $before = restoration_writes();

    $dry = restoration_refused(['email' => $email, '--as' => $as, '--dry-run' => true]);

    expect(restoration_writes())->toEqual($before)
        ->and(restoration_refused(['email' => $email, '--as' => $as]))->toBe($dry)
        ->and(restoration_writes())->toEqual($before);
})->with(['unknown user', 'unknown actor', 'not deactivated', 'self', 'rank', 'not manager', 'deactivated actor']);
