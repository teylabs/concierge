<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Event;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\RemoveUser;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Events\OwnerEstablished;
use Tey\Concierge\Events\UserRemoved;
use Tey\Concierge\Events\UserRolesChanged;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

function app_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

/** A host guard shielding one account from everyone, as #49's operator guard will. */
function shield(User $operator): void
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

it('establishes an owner idempotently and allows several', function () {
    Event::fake([OwnerEstablished::class]);

    $account = EstablishOwner::run($this->owner);
    $second = EstablishOwner::run(host_user('second'));

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->joined_at)->not->toBeNull()
        ->and(app_roles($this->owner))->toBe(['owner'])
        ->and(app_roles($second->user))->toBe(['owner'])
        ->and(app(Concierge::class)->isOwner($this->owner))->toBeTrue()
        ->and(app(Concierge::class)->isOwner($this->admin))->toBeFalse()
        ->and(Account::query()->count())->toBe(4);

    Event::assertDispatched(OwnerEstablished::class, fn (OwnerEstablished $e) => $e->userId === $second->user_id);
    Event::assertNotDispatched(OwnerEstablished::class, fn (OwnerEstablished $e) => $e->userId === $this->owner->id);
});

it('refuses the workspace actions, naming the app-wide ones', function () {
    expect(fn () => EstablishWorkspace::run(new Workspace(['name' => 'Acme']), $this->owner))
        ->toThrow(UnsupportedConfiguration::class, "concierge.mode = 'app' has no workspaces; use EstablishOwner.");
});

it('lets admins grant presenter but never admin or owner', function () {
    expect(app(AccountPolicy::class)->grantableRoles($this->admin))->toBe(['presenter'])
        ->and(app(AccountPolicy::class)->grantableRoles($this->owner))->toBe(['admin', 'presenter'])
        ->and(app(AccountPolicy::class)->grantableRoles($this->presenter))->toBe([])
        ->and(fn () => invite_user($this->admin, 'x@example.test', ['admin']))->toThrow(app_denied('not_grantable'))
        ->and(fn () => invite_user($this->owner, 'x@example.test', ['owner']))->toThrow(app_denied('not_grantable'))
        ->and(fn () => ChangeUserRoles::run($this->admin, $this->presenter, new GrantSet(['admin'])))->toThrow(app_denied('not_grantable'))
        ->and(fn () => invite_user($this->owner, 'x@example.test', ['super-admin']))->toThrow(app_denied('not_grantable'));

    Event::fake([UserRolesChanged::class]);

    ChangeUserRoles::run($this->owner, $this->presenter, new GrantSet(['admin']));

    expect(app_roles($this->presenter))->toBe(['admin']);
    Event::assertDispatched(UserRolesChanged::class, fn (UserRolesChanged $e) => $e->userId === $this->presenter->id
        && $e->roles === ['admin']
        && $e->actorId === $this->owner->id);
});

it('denies self, equal rank and non-managers', function () {
    $second = join_app($this->owner, 'second-admin', ['admin']);

    expect(fn () => ChangeUserRoles::run($this->admin, $this->admin, new GrantSet(['presenter'])))->toThrow(app_denied('self'))
        ->and(fn () => RemoveUser::run($this->owner, $this->owner))->toThrow(app_denied('self'))
        ->and(fn () => RemoveUser::run($this->admin, $second))->toThrow(app_denied('rank'))
        ->and(fn () => ChangeUserRoles::run($this->admin, $this->owner, new GrantSet(['presenter'])))->toThrow(app_denied('rank'))
        ->and(fn () => RemoveUser::run($this->presenter, $this->admin))->toThrow(app_denied('not_manager'))
        ->and(fn () => invite_user(host_user('nobody'), 'x@example.test'))->toThrow(app_denied('not_manager'));
});

it('lets an owner manage another owner, which is how ownership passes on', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    expect(app_roles($second))->toBe(['owner'])
        ->and(app(Concierge::class)->isOwner($second))->toBeTrue();

    // The new owner demotes the first: allowed, because an owner remains.
    ChangeUserRoles::run($second, $this->owner, new GrantSet(['admin']));

    expect(app_roles($this->owner))->toBe(['admin'])
        ->and(app(Concierge::class)->isOwner($this->owner))->toBeFalse()
        ->and(fn () => ChangeUserRoles::run($this->owner, $second, new GrantSet(['admin'])))->toThrow(app_denied('rank'));
});

it('protects the last owner from removal and demotion', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    RemoveUser::run($second, $this->owner);

    expect(User::query()->find($this->owner->id))->toBeNull()
        ->and(app(Concierge::class)->isOwner($second))->toBeTrue();

    // Nobody outranks the last owner, and they cannot manage themself.
    expect(fn () => ChangeUserRoles::run($this->admin, $second, new GrantSet(['admin'])))->toThrow(app_denied('rank'))
        ->and(fn () => ChangeUserRoles::run($second, $second, new GrantSet(['admin'])))->toThrow(app_denied('self'));

    // An operator may manage owners, but never the last one that is not the operator.
    $operator = app_owner('operator');
    shield($operator);

    expect(fn () => ChangeUserRoles::run($operator, $second, new GrantSet(['admin'])))->toThrow(app_denied('last_owner'))
        ->and(fn () => RemoveUser::run($operator, $second))->toThrow(app_denied('last_owner'))
        ->and(app_roles($second))->toBe(['owner']);

    $third = host_user('third');
    ChangeUserRoles::run($operator, $third, new GrantSet(['owner']));
    ChangeUserRoles::run($operator, $second, new GrantSet(['admin']));

    expect(app_roles($second))->toBe(['admin'])
        ->and(fn () => RemoveUser::run($operator, $third))->toThrow(app_denied('last_owner'));
});

it('honours the AccountGuard veto', function () {
    $operator = app_owner('operator');
    shield($operator);

    expect(fn () => ChangeUserRoles::run($this->owner, $operator, new GrantSet(['admin'])))->toThrow(app_denied('operator'))
        ->and(fn () => RemoveUser::run($this->owner, $operator))->toThrow(app_denied('operator'))
        ->and(app_roles($operator))->toBe(['owner']);

    // The operator still manages everyone else.
    ChangeUserRoles::run($operator, $this->admin, new GrantSet(['presenter']));

    expect(app_roles($this->admin))->toBe(['presenter']);
});

it('removes a user from the app: roles, account row and user', function () {
    Event::fake([UserRemoved::class]);
    $invitation = invite_user($this->admin, 'someone@example.test')->invitation;
    $presenterId = $this->presenter->id;

    RemoveUser::run($this->owner, $this->admin);

    expect(User::query()->find($this->admin->id))->toBeNull()
        ->and(Account::query()->find($this->admin->id))->toBeNull()
        ->and(DB::table('model_has_roles')->where('model_id', $this->admin->id)->count())->toBe(0)
        ->and($invitation->fresh()->inviter_id)->toBeNull()
        ->and(Account::query()->find($presenterId))->not->toBeNull();

    Event::assertDispatched(UserRemoved::class, fn (UserRemoved $e) => $e->userId === $this->admin->id && $e->actorId === $this->owner->id);
});

it('creates account rows on first write and reads a missing row as active', function () {
    $stranger = host_user('stranger');

    expect(Account::query()->find($stranger->id))->toBeNull()
        ->and(app(Concierge::class)->account($stranger)->isActive())->toBeTrue()
        ->and(app(Concierge::class)->account($stranger)->exists)->toBeFalse();

    expect(fn () => RemoveUser::run($stranger, $this->presenter))->toThrow(app_denied('not_manager'));

    // The transaction rolled back, so the actor's row is gone again...
    expect(Account::query()->find($stranger->id))->toBeNull();

    // ...but a successful write leaves the actor's row behind.
    ChangeUserRoles::run($this->owner, $stranger, new GrantSet(['presenter']));

    expect(Account::query()->find($this->owner->id))->not->toBeNull()
        ->and(Account::query()->find($stranger->id)->joined_at)->toBeNull();
});

it('denies a deactivated manager', function () {
    Account::query()->whereKey($this->admin->id)->update(['status' => AccountStatus::Deactivated->value]);

    expect(fn () => ChangeUserRoles::run($this->admin, $this->presenter, new GrantSet(['presenter'])))->toThrow(app_denied('not_member'))
        ->and(app(AccountPolicy::class)->grantableRoles($this->admin))->toBe([]);
});
