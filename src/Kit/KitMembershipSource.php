<?php

namespace Tey\Concierge\Kit;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\WorkspaceMember;

/**
 * A Laravel starter kit's teams, read and never written: the team's
 * memberships() over team_members, the user's teams() from HasTeams, and the
 * single role in the pivot's role column. Spatie teams stays off; these roles
 * are the kit's own (owner, admin, member).
 *
 * Extend it and change the properties if the app renamed the kit's pieces.
 */
class KitMembershipSource implements MembershipSource
{
    /** The relation on the team model to the kit's Membership pivot model. */
    protected string $membershipsRelation = 'memberships';

    /** The relation on the user model (HasTeams) to the teams they belong to. */
    protected string $teamsRelation = 'teams';

    protected string $roleColumn = 'role';

    /** The role value the kit treats as the team's owner. */
    protected string $ownerRole = 'owner';

    public function managesMembers(Workspace $workspace): bool
    {
        return false;
    }

    public function members(Workspace $workspace): array
    {
        return $this->memberships($workspace)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Model $membership) => $this->describe($workspace, (int) $membership->getAttribute('user_id'), $membership))
            ->values()
            ->all();
    }

    public function member(Workspace $workspace, Member $user): ?WorkspaceMember
    {
        $membership = $this->memberships($workspace)->where('user_id', $user->getKey())->first();

        return $membership ? $this->describe($workspace, (int) $user->getKey(), $membership) : null;
    }

    public function workspacesOf(Member $user): array
    {
        if (! $user instanceof Model || ! method_exists($user, $this->teamsRelation) || ! ($relation = $user->{$this->teamsRelation}()) instanceof BelongsToMany) {
            throw new UnsupportedConfiguration(sprintf(
                "[%s] must define %s(), the starter kit's HasTeams relation; the model set as concierge.models.user needs the relation. Add `use App\\Concerns\\HasTeams;` to that model, or extend KitMembershipSource and set \$teamsRelation to the renamed relation.",
                get_debug_type($user),
                $this->teamsRelation,
            ));
        }

        return $relation
            ->withPivot($this->roleColumn)
            ->orderByPivot('created_at')
            ->get()
            ->filter(fn (Model $team) => $team instanceof Workspace)
            ->map(fn (Workspace $team) => $this->describe($team, (int) $user->getKey(), $team->getRelation($relation->getPivotAccessor())))
            ->values()
            ->all();
    }

    /** @return HasMany<Model, Model> */
    protected function memberships(Workspace $workspace): HasMany
    {
        if (! $workspace instanceof Model || ! method_exists($workspace, $this->membershipsRelation) || ! ($relation = $workspace->{$this->membershipsRelation}()) instanceof HasMany) {
            throw new UnsupportedConfiguration(sprintf(
                "[%s] must define %s(), the starter kit's HasMany relation to team_members; set concierge.models.workspace to the kit's App\\Models\\Team, or extend KitMembershipSource and set \$membershipsRelation to the renamed relation.",
                get_debug_type($workspace),
                $this->membershipsRelation,
            ));
        }

        return $relation;
    }

    protected function describe(Workspace $workspace, int $userId, Model $membership): WorkspaceMember
    {
        $role = $membership->getAttribute($this->roleColumn);
        $role = $role instanceof BackedEnum ? (string) $role->value : (string) $role;
        $joined = $membership->getAttribute('created_at');

        /** @var Workspace&Model $workspace */
        return new WorkspaceMember(
            workspace: $workspace,
            userId: $userId,
            roles: $role === '' ? [] : [$role],
            owner: $role === $this->ownerRole,
            joinedAt: $joined instanceof CarbonInterface || $joined === null ? $joined : Carbon::parse($joined),
        );
    }
}
