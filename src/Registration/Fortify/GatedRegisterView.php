<?php

namespace Tey\Concierge\Registration\Fortify;

use Laravel\Fortify\Contracts\RegisterViewResponse;
use Tey\Concierge\Registration\Registration;

/**
 * Wraps Fortify's register view so the register page is a 404 in closed mode.
 * The decision is made per request, so a mode change needs no route rebuild.
 */
class GatedRegisterView implements RegisterViewResponse
{
    public function __construct(protected RegisterViewResponse $view) {}

    public function toResponse($request)
    {
        abort_if(Registration::closed(), 404);

        return $this->view->toResponse($request);
    }
}
