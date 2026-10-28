<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;

class PermissionWorkspaceContext implements WorkspaceContext
{
    protected ?Workspace $current = null;

    /** @var list<array{workspace: ?Workspace, team: mixed, refresh: array<int, Member>}> */
    protected array $entered = [];

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
        $this->enter($workspace, $refresh);

        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }

    public function enter(Workspace $workspace, iterable $refresh = []): void
    {
        ConciergeModels::assertModeSupported();
        $refresh = [...$refresh];
        $this->entered[] = ['workspace' => $this->current, 'team' => $this->registrar->getPermissionsTeamId(), 'refresh' => $refresh];
        $this->current = $workspace;
        $this->registrar->setPermissionsTeamId($workspace->getKey());
        $this->forgetCachedRelations($refresh);
    }

    public function leave(): void
    {
        $previous = array_pop($this->entered) ?? throw new LogicException('There is no workspace context to leave.');
        $this->current = $previous['workspace'];
        $this->registrar->setPermissionsTeamId($previous['team']);
        $this->forgetCachedRelations($previous['refresh']);
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
