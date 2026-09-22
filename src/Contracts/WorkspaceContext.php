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
}
