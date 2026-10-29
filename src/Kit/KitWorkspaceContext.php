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
use Tey\Concierge\Support\RequestMemo;

/**
 * The current team as a Laravel starter kit sees it: the team in the
 * {current_team} route parameter (a bound model or its slug), else the signed-in
 * user's current_team_id. It never checks membership; the concierge.member
 * middleware does that through the membership source.
 *
 * Spatie teams is off under the kit engine, so run() and enter() only set the
 * current workspace.
 *
 * Outside run() the route or user lookup is read once per request through
 * RequestMemo, keyed by the route value and the user's current team.
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
        protected ?RequestMemo $memo = null,
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
        $this->memo?->forget('kit.workspace.');
    }

    public function leave(): void
    {
        if ($this->entered === []) {
            throw new LogicException('There is no workspace context to leave.');
        }

        $this->running = array_pop($this->entered);
        $this->memo?->forget('kit.workspace.');
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
        $read = fn () => $model::query()->where((new $model)->getRouteKeyName(), $team)->first();

        /** @var (Model&Workspace)|null */
        return $this->memo ? $this->memo->remember("kit.workspace.route.{$model}.{$team}", $read) : $read();
    }

    protected function fromUser(): ?Workspace
    {
        $user = $this->auth->guard()->user();
        $id = $user instanceof Model ? $user->getAttribute($this->currentTeamColumn) : null;

        if ($id === null) {
            return null;
        }

        $model = ConciergeModels::workspace();
        $read = fn () => $model::query()->find($id);

        /** @var (Model&Workspace)|null */
        return $this->memo ? $this->memo->remember('kit.workspace.user.'.$model.'.'.(string) $id, $read) : $read();
    }
}
