<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\UpdateUser;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserUpdated;
use Tey\Concierge\Support\GrantSet;
use Workbench\App\Models\User;

function update_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

function update_invalid(string $field): Closure
{
    return fn (ValidationException $e) => expect(array_keys($e->errors()))->toBe([$field]);
}

beforeEach(function () {
    $this->owner = app_owner();
    $this->admin = join_app($this->owner, 'admin', ['admin']);
    $this->presenter = join_app($this->owner, 'presenter', ['presenter']);
});

it('updates the name and email and dispatches only what changed', function () {
    Event::fake([UserUpdated::class]);

    $user = UpdateUser::run($this->admin, $this->presenter, name: '  Pat Presenter ', email: 'pat@example.test');

    expect($user->name)->toBe('Pat Presenter')
        ->and($this->presenter->fresh()->email)->toBe('pat@example.test');

    Event::assertDispatched(UserUpdated::class, fn ($e) => $e->userId === $this->presenter->id
        && $e->actorId === $this->admin->id
        && $e->changes === [
            'name' => ['old' => 'Presenter', 'new' => 'Pat Presenter'],
            'email' => ['old' => 'presenter@example.test', 'new' => 'pat@example.test'],
        ]);
});

it('leaves a null field alone and dispatches nothing when nothing changed', function () {
    Event::fake([UserUpdated::class]);

    UpdateUser::run($this->admin, $this->presenter, name: 'Renamed');

    expect($this->presenter->fresh()->email)->toBe('presenter@example.test');
    Event::assertDispatched(UserUpdated::class, fn ($e) => array_keys($e->changes) === ['name']);

    Event::fake([UserUpdated::class]);
    UpdateUser::run($this->admin, $this->presenter, name: 'Renamed', email: 'presenter@example.test');
    UpdateUser::run($this->admin, $this->presenter);

    Event::assertNotDispatched(UserUpdated::class);
});

it('does not touch email verification or send anything when the email changes', function () {
    Notification::fake();
    $verifiedAt = $this->presenter->fresh()->email_verified_at;
    expect($verifiedAt)->not->toBeNull();

    UpdateUser::run($this->admin, $this->presenter, email: 'new@example.test');

    expect($this->presenter->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    Notification::assertNothingSent();

    $unverified = host_user('unverified', verified: false);
    ChangeUserRoles::run($this->owner, $unverified, new GrantSet(['presenter']));

    UpdateUser::run($this->admin, $unverified, email: 'still@example.test');

    expect($unverified->fresh()->email_verified_at)->toBeNull();
});

it('refuses an email another user has, whatever its case, but not the user\'s own', function () {
    expect(fn () => UpdateUser::run($this->admin, $this->presenter, email: 'ADMIN@example.test'))->toThrow(update_invalid('email'))
        ->and($this->presenter->fresh()->email)->toBe('presenter@example.test');

    UpdateUser::run($this->admin, $this->presenter, email: 'Presenter@Example.test');

    expect($this->presenter->fresh()->email)->toBe('Presenter@Example.test');
});

it('validates the name and email, keyed by field', function () {
    foreach (['', '   ', str_repeat('é', 256)] as $name) {
        expect(fn () => UpdateUser::run($this->admin, $this->presenter, name: $name))->toThrow(update_invalid('name'));
    }

    foreach (['', 'not-an-email', str_repeat('a', 250).'@example.test'] as $email) {
        expect(fn () => UpdateUser::run($this->admin, $this->presenter, email: $email))->toThrow(update_invalid('email'));
    }

    UpdateUser::run($this->admin, $this->presenter, name: str_repeat('é', 255));

    expect(mb_strlen($this->presenter->fresh()->name))->toBe(255);
});

it('refuses editing yourself', function () {
    expect(fn () => UpdateUser::run($this->admin, $this->admin, name: 'Me'))->toThrow(update_denied('self'))
        ->and(fn () => UpdateUser::run($this->owner, $this->owner, name: 'Me'))->toThrow(update_denied('self'))
        ->and($this->admin->fresh()->name)->toBe('Admin');
});

it('refuses equal or higher rank, and a user who cannot manage', function () {
    $other = join_app($this->owner, 'other', ['admin']);

    expect(fn () => UpdateUser::run($this->admin, $other, name: 'X'))->toThrow(update_denied('rank'))
        ->and(fn () => UpdateUser::run($this->admin, $this->owner, name: 'X'))->toThrow(update_denied('rank'))
        ->and(fn () => UpdateUser::run($this->presenter, $this->admin, name: 'X'))->toThrow(update_denied('not_manager'))
        ->and($other->fresh()->name)->toBe('Other')
        ->and($this->owner->fresh()->name)->toBe('Owner');
});

it('lets an owner edit a co-owner, and refuses when no other owner the actor can manage remains', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    UpdateUser::run($this->owner, $second, name: 'Second Owner');

    expect($second->fresh()->name)->toBe('Second Owner');

    // A guard shielding the actor: the other owner could not replace them, so editing that owner is the last-owner case.
    app()->instance(AccountGuard::class, new class($this->owner) implements AccountGuard
    {
        public function __construct(private User $shielded) {}

        public function authorize(string $operation, Member $actor, Member $target): Response
        {
            return $target->getKey() === $this->shielded->getKey() ? Response::deny('Protected.', 'protected') : Response::allow();
        }
    });
    app()->forgetInstance(AccountPolicy::class);
    app()->forgetInstance(UpdateUser::class);

    expect(fn () => UpdateUser::run($this->owner, $second, name: 'Again'))->toThrow(update_denied('last_owner'))
        ->and($second->fresh()->name)->toBe('Second Owner');
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
    app()->forgetInstance(UpdateUser::class);

    Event::fake([UserUpdated::class]);

    expect(fn () => UpdateUser::run($this->admin, $this->presenter, name: 'X'))->toThrow(update_denied('protected'))
        ->and($this->presenter->fresh()->name)->toBe('Presenter');
    Event::assertNotDispatched(UserUpdated::class);
});

it('checks authorization before validation, so a refused actor learns nothing about emails', function () {
    expect(fn () => UpdateUser::run($this->presenter, $this->admin, email: 'owner@example.test'))->toThrow(update_denied('not_manager'));
});
