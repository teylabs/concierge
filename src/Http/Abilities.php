<?php

namespace Tey\Concierge\Http;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;

/**
 * The Gate abilities concierge's controllers check before they render a page
 * or call an action. Each is defined only when the app has not defined it, so
 * an app's own Gate::define() wins, before or after concierge boots.
 *
 * The actions check the grant rules again, so an ability can narrow what the
 * routes allow but never widen it: rebind GrantPolicy for that.
 */
final class Abilities
{
    /** See the members list and a member's page: (user, Workspace). */
    public const string VIEW_MEMBERS = 'concierge.view-members';

    /** Open the invite form and send invitations: (user, Workspace). */
    public const string INVITE_MEMBERS = 'concierge.invite-members';

    /** Change a member's roles, suspend, reinstate or remove them: (user, Membership). */
    public const string MANAGE_MEMBER = 'concierge.manage-member';

    /** Pass the workspace's ownership to this member: (user, Membership). */
    public const string TRANSFER_OWNERSHIP = 'concierge.transfer-ownership';

    /** Resend or revoke an invitation: (user, Invitation). */
    public const string MANAGE_INVITATION = 'concierge.manage-invitation';

    public static function define(): void
    {
        $abilities = [
            self::VIEW_MEMBERS => static function (Member $user, Workspace $workspace): Response {
                $membership = self::concierge()->membership($user, $workspace);

                return $membership?->isActive() && ! self::concierge()->isScopedGuest($membership)
                    ? Response::allow()
                    : Response::deny(__('You are not an active member of this workspace.'), 'not_member');
            },
            self::INVITE_MEMBERS => static function (Member $user, Workspace $workspace): Response {
                $management = self::policy()->authorizeManagement($user, $workspace);

                if ($management->denied()) {
                    return $management;
                }

                return self::policy()->grantableRoles($user, $workspace) !== []
                    ? Response::allow()
                    : Response::deny(__('You cannot grant any role.'), 'not_grantable');
            },
            self::MANAGE_MEMBER => static fn (Member $user, Membership $membership): Response => self::policy()->authorizeManagement($user, $membership->workspace, $membership),
            self::TRANSFER_OWNERSHIP => static function (Member $user, Membership $recipient): Response {
                $owner = self::concierge()->ownerOf($recipient->workspace);

                return $owner?->isActive() && (int) $owner->user_id === (int) $user->getKey()
                    ? Response::allow()
                    : Response::deny(__('Only the workspace owner can transfer ownership.'), 'not_owner');
            },
            self::MANAGE_INVITATION => static fn (Member $user, Invitation $invitation): Response => self::policy()->authorizeManagement($user, $invitation->workspace),
        ];

        foreach ($abilities as $ability => $callback) {
            if (! Gate::has($ability)) {
                Gate::define($ability, $callback);
            }
        }
    }

    private static function concierge(): Concierge
    {
        return app(Concierge::class);
    }

    private static function policy(): GrantPolicy
    {
        return app(GrantPolicy::class);
    }
}
