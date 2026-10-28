<?php

namespace Tey\Concierge\Kit;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Support\ConciergeModels;

/**
 * The current team as a Laravel starter kit sees it: the team in the
 * {current_team} route parameter (a bound model or its slug), else the signed-in
 * user's current_team_id. It never checks membership; the concierge.member
 * middleware does that through the membership source.
 *
 * Spatie teams is off under the kit engine, so run() and enter() only set the
 * current workspace.
 */
class KitWorkspaceContext implements WorkspaceContext
{
    protected string $routeParameter = 'current_team';

    protected string $currentTeamColumn = 'current_team_id';

    protected ?Workspace $running = null;

    /** @var list<?Workspace> */
    protected array $entered = [];

    public function __construct(
        protected Container $app,
        protected AuthFactory $auth,
    ) {}

    public function current(): ?Workspace
    {
        return $this->running ?? $this->fromRoute() ?? $this->fromUser();
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
        $this->entered[] = $this->running;
        $this->running = $workspace;
    }

    public function leave(): void
    {
        if ($this->entered === []) {
            throw new LogicException('There is no workspace context to leave.');
        }

        $this->running = array_pop($this->entered);
    }

    protected function fromRoute(): ?Workspace
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $team = $this->app->make('request')->route($this->routeParameter);

        if ($team instanceof Workspace || $team === null) {
            return $team;
        }

        if (! is_string($team)) {
            return null;
        }

        $model = ConciergeModels::workspace();

        /** @var (Model&Workspace)|null */
        return $model::query()->where((new $model)->getRouteKeyName(), $team)->first();
    }

    protected function fromUser(): ?Workspace
    {
        $user = $this->auth->guard()->user();
        $id = $user instanceof Model ? $user->getAttribute($this->currentTeamColumn) : null;

        if ($id === null) {
            return null;
        }

        /** @var (Model&Workspace)|null */
        return ConciergeModels::workspace()::query()->find($id);
    }
}
