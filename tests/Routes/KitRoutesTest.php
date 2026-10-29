<?php

use Illuminate\Routing\Router;
use Tey\Concierge\Concierge;
use Tey\Concierge\Http\ConciergeRoutes;
use Tey\Concierge\Models\Invitation;

/*
 * The kit engine reads a starter kit's teams and never writes them (#57), so
 * concierge serves no routes there even with concierge.routes.enabled: the
 * kit's own team pages stay in charge.
 */

it('registers no concierge routes on the kit engine', function () {
    expect(config('concierge.routes.enabled'))->toBeTrue()
        ->and(collect(app(Router::class)->getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge.'))->all())->toBe([])
        ->and(ConciergeRoutes::linksInvitations())->toBeFalse()
        ->and(app(Concierge::class)->invitationUrl(new Invitation, 'token'))->toBeNull();

    $this->get('/members')->assertNotFound();
});
