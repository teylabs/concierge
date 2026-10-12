<?php

namespace Tey\Concierge\Registration\Fortify;

use Laravel\Fortify\Contracts\RegisterViewResponse;
use Tey\Concierge\Registration\Registration;
use Tey\Concierge\Rules\MayRegister;

/**
 * Wraps Fortify's register view so the register page is a 404 in closed mode.
 * The decision is made per request, so a mode change needs no route rebuild.
 * A register link with ?invitation=TOKEN has its token remembered in the
 * session, so the kit's form need not post it.
 */
class GatedRegisterView implements RegisterViewResponse
{
    public function __construct(protected RegisterViewResponse $view) {}

    public function toResponse($request)
    {
        abort_if(Registration::closed(), 404);

        Registration::remember($request, $request->query(MayRegister::TOKEN_INPUT));

        return $this->view->toResponse($request);
    }
}
