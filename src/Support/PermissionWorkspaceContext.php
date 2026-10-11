<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;

class PermissionWorkspaceContext implements WorkspaceContext
{
    protected ?Workspace $current = null;

    public function __construct(
        protected PermissionRegistrar $registrar,
        protected AuthFactory $auth,
    ) {}

    public function current(): ?Workspace
    {
        return $this->current;
    }

    public function run(Workspace $workspace, Closure $callback, iterable $refresh = []): mixed
    {
        ConciergeModels::assertModeSupported();

        $refresh = [...$refresh];
        $previous = $this->current;
        $previousTeam = $this->registrar->getPermissionsTeamId();

        $this->current = $workspace;
        $this->registrar->setPermissionsTeamId($workspace->getKey());
        $this->forgetCachedRelations($refresh);

        try {
            return $callback();
        } finally {
            $this->current = $previous;
            $this->registrar->setPermissionsTeamId($previousTeam);
            $this->forgetCachedRelations($refresh);
        }
    }

    /** @param  array<int, Member>  $members */
    protected function forgetCachedRelations(array $members): void
    {
        $guard = $this->auth->guard();

        if ($guard->hasUser()) {
            $members[] = $guard->user();
        }

        foreach ($members as $member) {
            if ($member instanceof Model) {
                $member->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }
}
