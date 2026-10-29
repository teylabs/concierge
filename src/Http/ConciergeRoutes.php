<?php

namespace Tey\Concierge\Http;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use SensitiveParameter;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Http\Controllers\AcceptInvitationController;
use Tey\Concierge\Http\Controllers\InvitationController;
use Tey\Concierge\Http\Controllers\InviteMemberController;
use Tey\Concierge\Http\Controllers\MemberController;
use Tey\Concierge\Http\Controllers\MembersController;
use Tey\Concierge\Http\Middleware\ConfirmPasswordWhenAvailable;
use Tey\Concierge\Http\Middleware\UseCurrentWorkspace;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;

/**
 * Concierge's named concierge.* routes. They exist only when concierge is set
 * up, membership is on and concierge.routes.enabled is true, which
 * concierge:install writes. Controllers are resolved from the container, so
 * binding a controller class to your own subclass replaces it.
 */
final class ConciergeRoutes
{
    /** The route an invitation email links to. */
    public const string ACCEPT = 'concierge.invitations.show';

    public static function enabled(): bool
    {
        return config('concierge.routes.enabled') === true;
    }

    /** Whether this app serves the member routes: workspace mode on concierge's own engine. */
    public static function servesMembers(): bool
    {
        return Mode::configured() === Mode::Workspaces && WorkspaceEngine::configured() === WorkspaceEngine::Concierge;
    }

    /** Whether this app serves the invitation pages: both modes, but not the kit engine. */
    public static function servesInvitations(): bool
    {
        return Mode::configured() === Mode::App || self::servesMembers();
    }

    public static function register(Router $router): void
    {
        $prefix = trim((string) config('concierge.routes.prefix', ''), '/');

        if (self::servesMembers()) {
            $router->middleware([...(array) config('concierge.routes.middleware', ['web', 'auth']), UseCurrentWorkspace::class, 'concierge.member'])
                ->prefix($prefix)
                ->name('concierge.')
                ->where(['membership' => '[0-9]+'])
                ->group(function (Router $router) {
                    $router->get('members', [MembersController::class, 'index'])->name('members.index');
                    $router->get('members/invite', [InviteMemberController::class, 'create'])->name('members.create');
                    $router->post('members/invite', [InviteMemberController::class, 'store'])->name('members.store');
                    $router->match(['post', 'put'], 'members/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
                    $router->delete('members/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
                    $router->get('members/{membership}', [MemberController::class, 'show'])->name('members.show');
                    $router->put('members/{membership}/roles', [MemberController::class, 'updateRoles'])->name('members.roles');
                    $router->post('members/{membership}/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
                    $router->post('members/{membership}/reinstate', [MemberController::class, 'reinstate'])->name('members.reinstate');
                    $router->delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
                    $router->post('members/{membership}/transfer-ownership', [MemberController::class, 'transferOwnership'])
                        ->middleware(ConfirmPasswordWhenAvailable::class)
                        ->name('members.transfer-ownership');
                });
        }

        if (self::servesInvitations()) {
            $router->middleware((array) config('concierge.routes.invitation_middleware', ['web']))
                ->prefix($prefix)
                ->name('concierge.')
                ->group(function (Router $router) {
                    $router->get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
                    $router->post('invitations/{token}/accept', [AcceptInvitationController::class, 'store'])->name('invitations.accept');
                    $router->post('invitations/{token}/switch-account', [AcceptInvitationController::class, 'switchAccount'])->name('invitations.switch-account');
                });
        }

        // Names are given after each route is added; index them now, not only when the app's routes load.
        $router->getRoutes()->refreshNameLookups();
    }

    /**
     * The URL an invitation email links to, or null when the routes are off.
     * Concierge::invitationUrlUsing() replaces it.
     */
    public static function invitationUrl(#[SensitiveParameter] string $token): ?string
    {
        return self::linksInvitations() ? route(self::ACCEPT, ['token' => $token]) : null;
    }

    /** Whether invitation emails link to concierge's own invitation page. */
    public static function linksInvitations(): bool
    {
        return self::enabled() && app('router')->has(self::ACCEPT);
    }

    /**
     * Where each MembersIndex action goes on these routes. Copying an
     * invitation link has no route yet, so it is left out.
     *
     * @return Closure(string, ?Model): ?string
     */
    public static function memberUrls(): Closure
    {
        return static function (string $action, ?Model $subject = null): ?string {
            $name = [
                'index' => 'members.index',
                'invite' => 'members.create',
                'member.show' => 'members.show',
                'member.updateGrants' => 'members.roles',
                'member.suspend' => 'members.suspend',
                'member.reinstate' => 'members.reinstate',
                'member.remove' => 'members.destroy',
                'member.transferOwnership' => 'members.transfer-ownership',
                'invitation.resend' => 'invitations.resend',
                'invitation.revoke' => 'invitations.destroy',
            ][$action] ?? null;

            return match (true) {
                $name === null => null,
                $subject instanceof Membership => route("concierge.{$name}", ['membership' => $subject->getKey()]),
                $subject instanceof Invitation => route("concierge.{$name}", ['invitation' => $subject->public_id]),
                default => route("concierge.{$name}"),
            };
        };
    }
}
