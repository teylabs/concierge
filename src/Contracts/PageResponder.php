<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Data\Props;

/**
 * Turns a page's props into the response for concierge's routes. The default
 * renders the Inertia component when Inertia is installed and returns the
 * props as JSON otherwise. Bind your own to render with another frontend.
 */
interface PageResponder
{
    public function render(Request $request, string $component, Props $props): Responsable|Response;
}
