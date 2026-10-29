<?php

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Data\Props;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Http\Abilities;
use Tey\Concierge\Http\ConciergeRoutes;
use Tey\Concierge\Http\Controllers\MembersController;
use Tey\Concierge\Http\InertiaPageResponder;
use Tey\Concierge\Http\Middleware\ConfirmPasswordWhenAvailable;
use Tey\Concierge\Support\MembersIndex;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->acme = host_workspace('Acme', $this->owner);
    $this->carol = host_user('carol');
    $this->carolship = join_as($this->acme, $this->owner, $this->carol);
});

class AppMembersController extends MembersController
{
    public function index(Request $request, MembersIndex $index): Responsable|Response
    {
        return response()->json(['app' => true]);
    }
}

it('names every route concierge.*', function () {
    $names = collect(app(Router::class)->getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge.'))->sort()->values()->all();

    expect($names)->toBe([
        'concierge.invitations.accept',
        'concierge.invitations.destroy',
        'concierge.invitations.resend',
        'concierge.invitations.show',
        'concierge.invitations.switch-account',
        'concierge.members.create',
        'concierge.members.destroy',
        'concierge.members.index',
        'concierge.members.reinstate',
        'concierge.members.roles',
        'concierge.members.show',
        'concierge.members.store',
        'concierge.members.suspend',
        'concierge.members.transfer-ownership',
    ]);
});

it('uses an app controller bound in place of concierge\'s', function () {
    app()->bind(MembersController::class, AppMembersController::class);

    $this->actingAs($this->owner)->getJson('/members')->assertOk()->assertExactJson(['app' => true]);
});

it('lets an app ability defined after boot narrow what the routes allow', function () {
    Gate::define(Abilities::MANAGE_MEMBER, fn ($user, $membership) => false);

    $this->actingAs($this->owner)->postJson("/members/{$this->carolship->id}/suspend")->assertForbidden();

    expect($this->carolship->fresh()->status)->toBe(MembershipStatus::Active);
});

it('keeps an app ability defined before concierge defines its own', function () {
    Gate::define(Abilities::VIEW_MEMBERS, fn ($user, $workspace) => false);
    Abilities::define();

    $this->actingAs($this->owner)->getJson('/members')->assertForbidden();
});

it('renders through a bound page responder and the configured component', function () {
    config()->set('concierge.routes.pages.members', 'Team/Members');
    app()->bind(PageResponder::class, fn () => new class implements PageResponder
    {
        public function render(Request $request, string $component, Props $props): Responsable|Response
        {
            return response()->json(['component' => $component, 'workspace' => $props->toArray()['workspace']['name']]);
        }
    });

    $this->actingAs($this->owner)->getJson('/members')->assertExactJson(['component' => 'Team/Members', 'workspace' => 'Acme']);
});

it('defaults the page component names to the concierge:pages wrappers', function () {
    $this->actingAs($this->owner)->getJson('/members/invite')->assertHeader(InertiaPageResponder::HEADER, 'Concierge/InviteMember');
    $this->actingAs($this->owner)->getJson("/members/{$this->carolship->id}")->assertHeader(InertiaPageResponder::HEADER, 'Concierge/Member');
});

it('registers under the configured prefix and middleware', function () {
    $router = app(Router::class);
    $router->setRoutes(new RouteCollection);
    config()->set('concierge.routes.prefix', '/team/');
    config()->set('concierge.routes.middleware', ['web', 'auth', 'verified']);
    config()->set('concierge.routes.invitation_middleware', ['web', 'throttle:6,1']);

    ConciergeRoutes::register($router);
    $router->getRoutes()->refreshNameLookups();

    $members = $router->getRoutes()->getByName('concierge.members.index');
    $accept = $router->getRoutes()->getByName('concierge.invitations.show');

    expect($members->uri())->toBe('team/members')
        ->and($members->middleware())->toContain('web', 'auth', 'verified', 'concierge.member')
        ->and($accept->uri())->toBe('team/invitations/{token}')
        ->and($accept->middleware())->toBe(['web', 'throttle:6,1'])
        ->and($router->getRoutes()->getByName('concierge.members.transfer-ownership')->middleware())->toContain(ConfirmPasswordWhenAvailable::class);
});
