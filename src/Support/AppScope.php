<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;

/**
 * App-wide mode's scope: the whole app. Bound as the WorkspaceContext so code
 * that asks for the current workspace gets null, and code that tries to run
 * inside one is told which app-wide action to use instead. Roles are plain
 * Spatie roles with teams off, so reads need no team to be set.
 */
class AppScope implements WorkspaceContext
{
    public function current(): ?Workspace
    {
        return null;
    }

    public function run(Workspace $workspace, Closure $callback, iterable $refresh = []): mixed
    {
        throw new UnsupportedConfiguration(
            "concierge.mode = 'app' has no workspaces; use the app-wide actions (InviteUser, EstablishOwner, ChangeUserRoles, RemoveUser)."
        );
    }

    /**
     * The user's account row, or an unsaved active one when there is none:
     * the users table is the roster and the row is only the overlay.
     */
    public function account(Member $user): Account
    {
        return ConciergeModels::account()::query()->find($user->getKey())
            ?? ConciergeModels::account()::make(['user_id' => $user->getKey(), 'status' => AccountStatus::Active]);
    }

    /** @return list<string> */
    public function roleNames(Member $user): array
    {
        if ($user instanceof Model) {
            $user->unsetRelation('roles');
        }

        return Roles::sort($user->roles()->pluck('name')->all());
    }

    public function isOwner(Member $user): bool
    {
        return in_array(Roles::owner(), $this->roleNames($user), true);
    }

    /**
     * Every user holding the owner role, ascending by key.
     *
     * @return list<Member&Model>
     */
    public function owners(): array
    {
        $role = Roles::find(Roles::owner());

        if (! $role) {
            return [];
        }

        /** @var list<Member&Model> $owners */
        $owners = ConciergeModels::user()::query()
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->orderBy((new (ConciergeModels::user()))->getKeyName())
            ->get()
            ->all();

        return $owners;
    }

    /** @return list<int> */
    public function ownerIds(): array
    {
        return array_map(fn (Model $owner) => (int) $owner->getKey(), $this->owners());
    }
}
