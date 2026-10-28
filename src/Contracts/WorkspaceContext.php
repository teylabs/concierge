<?php

namespace Tey\Concierge\Contracts;

use Closure;

interface WorkspaceContext
{
    public function current(): ?Workspace;

    /**
     * Run the callback with the workspace as the Spatie permission team,
     * restoring the previous team afterwards, even on failure.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  iterable<Member>  $refresh  members whose cached role/permission relations must be dropped
     * @return TReturn
     */
    public function run(Workspace $workspace, Closure $callback, iterable $refresh = []): mixed;

    /**
     * Make the workspace current (and the Spatie permission team) until the
     * matching leave(), for code that switches context across calls rather
     * than around one callback, such as a tenant switch task. Entries nest:
     * each leave() restores what its enter() replaced.
     *
     * @param  iterable<Member>  $refresh  members whose cached role/permission relations must be dropped
     */
    public function enter(Workspace $workspace, iterable $refresh = []): void;

    /**
     * Restore what the latest enter() replaced. Leaving with nothing entered
     * throws a LogicException rather than guess a context.
     */
    public function leave(): void;
}
