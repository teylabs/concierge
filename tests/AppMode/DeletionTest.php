<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\RemoveUser;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\UserDeletionGuard;
use Workbench\App\Models\SoftDeletingUser;
use Workbench\App\Models\User;

/** What a starter kit's profile controller does: confirm the password, then delete the model. */
function kit_delete(User $user): void
{
    expect(Hash::check('secret', $user->password))->toBeTrue();

    $user->delete();
}

function last_owner_refused(): Closure
{
    return fn (ValidationException $e) => expect($e->errors())->toBe([
        'password' => ['This account is the last owner; make someone else an owner before deleting it.'],
    ]);
}

beforeEach(function () {
    $this->owner = app_owner();
    User::query()->update(['password' => Hash::make('secret')]);
    $this->owner->refresh();
});

it('lets a user delete their own account the way a starter kit does', function () {
    $alice = join_app($this->owner, 'alice');
    $alice->forceFill(['password' => Hash::make('secret')])->save();

    expect(Account::query()->find($alice->id))->not->toBeNull();

    kit_delete($alice);

    expect(User::query()->find($alice->id))->toBeNull()
        ->and(Account::query()->find($alice->id))->toBeNull();
});

it('lets an owner with a co-owner delete their account, and refuses the last owner', function () {
    expect(fn () => kit_delete($this->owner))->toThrow(last_owner_refused())
        ->and(User::query()->find($this->owner->id))->not->toBeNull()
        ->and(Account::query()->find($this->owner->id))->not->toBeNull();

    $second = host_user('second');
    $second->forceFill(['password' => Hash::make('secret')])->save();
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    kit_delete($this->owner);

    expect(User::query()->find($this->owner->id))->toBeNull()
        ->and(Account::query()->find($this->owner->id))->toBeNull()
        ->and(fn () => kit_delete($second->fresh()))->toThrow(last_owner_refused());
});

it('composes with RemoveUser without a second refusal', function () {
    $second = host_user('second');
    ChangeUserRoles::run($this->owner, $second, new GrantSet(['owner']));

    RemoveUser::run($second, $this->owner);

    expect(User::query()->find($this->owner->id))->toBeNull()
        ->and(fn () => RemoveUser::run($second, $second))->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('self'));
});

it('keeps the accounts row through a soft delete and drops it on a force delete', function () {
    // A host whose user model soft-deletes. Spatie keys role rows by morph class, so the
    // owners for this test are established on that model.
    config()->set('concierge.models.user', SoftDeletingUser::class);
    config()->set('auth.providers.users.model', SoftDeletingUser::class);
    UserDeletionGuard::register();

    $owner = SoftDeletingUser::query()->create(['name' => 'Soft', 'email' => 'soft@example.test', 'email_verified_at' => now()]);
    $second = SoftDeletingUser::query()->create(['name' => 'Second', 'email' => 'second@example.test', 'email_verified_at' => now()]);
    EstablishOwner::run($owner);

    expect(fn () => $owner->delete())->toThrow(last_owner_refused())
        ->and(fn () => $owner->forceDelete())->toThrow(last_owner_refused())
        ->and(SoftDeletingUser::query()->find($owner->id))->not->toBeNull();

    ChangeUserRoles::run($owner, $second, new GrantSet(['owner']));

    // A refused forceDelete() leaves Laravel's force flag set on that instance, so use a fresh one
    // as a host would on its next request.
    $owner = $owner->fresh();
    $owner->delete();

    expect(SoftDeletingUser::query()->find($owner->id))->toBeNull()
        ->and(SoftDeletingUser::withTrashed()->find($owner->id))->not->toBeNull()
        ->and(Account::query()->find($owner->id))->not->toBeNull();

    // The last active owner on a soft delete is the one still present: the trashed owner does not count.
    expect(fn () => $second->delete())->toThrow(last_owner_refused());

    $owner->restore();
    $owner->forceDelete();

    expect(SoftDeletingUser::withTrashed()->find($owner->id))->toBeNull()
        ->and(Account::query()->find($owner->id))->toBeNull();
});
