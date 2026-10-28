<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\ScopedAccess;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Enums\Mode;

/**
 * The default ScopedAccess, over concierge_membership_scopes. Read fresh on
 * every call, so a revoked scope or a suspended or removed membership denies
 * at once. App-wide mode has no scopes and always denies.
 */
class MembershipScopedAccess implements ScopedAccess
{
    public function __construct(protected Concierge $concierge) {}

    public function allows(Member $user, Model $record, ?Workspace $workspace = null): bool
    {
        if ($this->concierge->mode() !== Mode::Workspaces || ! $record->exists || $record->getKey() === null) {
            return false;
        }

        $workspaceId = $this->workspaceId($record, $workspace);

        if ($workspaceId === null) {
            return false;
        }

        $scope = Scope::of($record);
        $memberships = (new (ConciergeModels::membership()))->getTable();

        return ConciergeModels::membershipScope()::query()
            ->whereNull('revoked_at')
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->whereIn('membership_id', ConciergeModels::membership()::query()
                ->where("{$memberships}.workspace_id", $workspaceId)
                ->where("{$memberships}.user_id", $user->getKey())
                ->where("{$memberships}.status", MembershipStatus::Active->value)
                ->select("{$memberships}.id"))
            ->exists();
    }

    protected function workspaceId(Model $record, ?Workspace $workspace): ?int
    {
        // Read from the raw attributes, so a model in strict mode without the column does not throw.
        $id = $workspace?->getKey()
            ?? ($record->getAttributes()['workspace_id'] ?? null)
            ?? $this->concierge->context()->current()?->getKey();

        return $id === null ? null : (int) $id;
    }
}
