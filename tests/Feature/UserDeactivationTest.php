<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Actions\RestoreUser;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\GrantSet;

// Workspace mode has no app-wide roles, so the host decides who may deactivate: here a platform operator.
beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->member = host_user('member');
    $this->membership = join_as($this->workspace, $this->owner, $this->member, ['admin']);
    $this->member->forceFill(['password' => Hash::make('secret')])->save();
    $this->operator = host_user('operator');

    app()->instance(AccountPolicy::class, new class($this->operator) implements AccountPolicy
    {
        public function __construct(private Member $operator) {}

        public function grantableRoles(Member $actor): array
        {
            return [];
        }

        public function authorizeManagement(Member $actor, ?Member $target = null): Response
        {
            return match (true) {
                $actor->getKey() !== $this->operator->getKey() => Response::deny('Not an operator.', 'not_manager'),
                $target?->getKey() === $actor->getKey() => Response::deny('You cannot manage your own account.', 'self'),
                default => Response::allow(),
            };
        }

        public function authorizeGrant(Member $actor, GrantSet $grants, ?Member $target = null): Response
        {
            return $this->authorizeManagement($actor, $target);
        }
    });
});

it('deactivates and restores a user in workspace mode, leaving memberships and roles alone', function () {
    DeactivateUser::run($this->operator, $this->member, reason: 'Left');

    $membership = $this->membership->fresh();

    expect(Account::query()->find($this->member->id)->isActive())->toBeFalse()
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and(roles_in($membership))->toBe(['admin']);

    RestoreUser::run($this->operator, $this->member);

    expect(Account::query()->find($this->member->id)->isActive())->toBeTrue()
        ->and($membership->fresh()->status)->toBe(MembershipStatus::Active)
        ->and(roles_in($membership->fresh()))->toBe(['admin']);
});

it('applies the account policy in workspace mode', function () {
    expect(fn () => DeactivateUser::run($this->owner, $this->member))->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('not_manager'))
        ->and(fn () => DeactivateUser::run($this->operator, $this->operator))->toThrow(fn (AuthorizationException $e) => expect($e->getCode())->toBe('self'));
});

it('refuses sign-in and signs out a session in workspace mode', function () {
    Route::middleware(['web', 'auth', 'concierge.active'])->get('/_dashboard', fn () => 'ok');

    DeactivateUser::run($this->operator, $this->member);

    expect(fn () => Auth::guard('web')->attempt(['email' => $this->member->email, 'password' => 'secret']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['email' => ['This account is deactivated.']]));

    $this->actingAs($this->member)->getJson('/_dashboard')->assertForbidden();

    RestoreUser::run($this->operator, $this->member);

    expect(Auth::guard('web')->attempt(['email' => $this->member->email, 'password' => 'secret']))->toBeTrue();
});
