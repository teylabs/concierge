<?php

namespace Tey\Concierge\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Http\ConciergeRoutes;
use Tey\Concierge\Http\Controllers\Concerns\ResolvesWorkspaceRoutes;
use Tey\Concierge\Support\MembersIndex;

/** The members list of the current workspace, with its Members and Invitations tabs. */
class MembersController
{
    use ResolvesWorkspaceRoutes;

    public function __construct(protected PageResponder $pages) {}

    public function index(Request $request, MembersIndex $index): Responsable|Response
    {
        $workspace = $this->viewableWorkspace($request);
        $props = $index->urlsUsing(ConciergeRoutes::memberUrls())->props($this->actor($request), $workspace, $request);

        return $this->pages->render($request, (string) config('concierge.routes.pages.members', 'Concierge/Members'), $props);
    }
}
