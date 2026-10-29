<?php

use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\MembershipScope;
use Tey\Concierge\Models\Ownership;
use Tey\Concierge\Models\TenantInvitation;

return [
    /*
     * 'app' (invitations and roles apply to the whole app; Spatie teams off; no
     * workspace model or table) or 'workspaces' (they apply per workspace; Spatie
     * teams on). Set it here, never from .env: it describes the schema. Null means
     * 'app', except that a config without the key and with a models.workspace is
     * read as 'workspaces' until 1.0.
     */
    'mode' => null,

    /*
     * First owners for concierge:owners, run after migrate on every deploy.
     * Comma-separated emails: whitespace and blanks are dropped. App mode allows
     * several; workspace mode needs one and --workspace=<existing key>.
     * Only newly created users receive a password link; existing users are never
     * emailed or have their profile changed. Separate from platform_owners.
     */
    'owners' => array_values(array_filter(array_map('trim', explode(',', env('CONCIERGE_OWNERS', ''))), fn (string $email) => $email !== '')),

    'workspaces' => [
        /*
         * Read in workspace mode only. 'concierge' keeps members in concierge's own
         * tables, on Spatie teams. 'kit' reads a Laravel starter kit's teams (its
         * team_members pivot and role column) and never writes them; Spatie teams
         * stays off. Set it here, never from .env.
         */
        'engine' => 'concierge',

        /*
         * Where a user goes when the workspace they asked for is no longer
         * available to them (removed, left or suspended): a path on this app,
         * such as a page to pick another workspace or create one. JSON requests
         * get a 403, and a workspace they never belonged to stays a 404.
         */
        'no_workspace_url' => '/',
    ],

    'features' => [
        // Set to false for an app that only wants the local-login feature:
        // no membership bindings, middleware alias, commands or automatic migration.
        'membership' => true,

        // Platform-level tenant invitations: a platform owner invites an email and
        // accepting provisions that person's OWN workspace. Off by default: no table,
        // limiter or Gate definition until true. Set it before publishing migrations.
        // Workspace mode only: with 'mode' => 'app' it fails at boot.
        'tenant_invitations' => false,
    ],

    'models' => [
        'user' => null,
        'workspace' => null,
        'membership' => Membership::class,
        'membership_scope' => MembershipScope::class,
        'ownership' => Ownership::class,
        'invitation' => Invitation::class,
        'tenant_invitation' => TenantInvitation::class,
        'account' => Account::class,
    ],

    'guard' => null,

    /*
     * Ordered highest rank first. Each role is a global Spatie role row (team
     * NULL in workspace mode). In workspace mode assignments are scoped to a
     * workspace; in app mode they apply to the whole app and the owner role is
     * the top role, held by one or several owners.
     *
     * A role with 'scoped' => true is only for scoped invitations (one record in
     * a workspace): it is never offered or granted workspace-wide, and should
     * carry no permissions. For example, last in the list:
     * 'guest' => ['label' => 'Guest', 'grantable_by' => ['owner', 'admin'], 'scoped' => true],
     */
    'roles' => [
        'owner' => ['label' => 'Owner', 'grantable_by' => []],
        'admin' => ['label' => 'Admin', 'grantable_by' => ['owner']],
        'presenter' => ['label' => 'Presenter', 'grantable_by' => ['owner', 'admin']],
    ],

    'owner_role' => 'owner',

    'transfer_demotes_to' => 'admin',

    'managers' => ['owner', 'admin'],

    'invitations' => [
        'expires_after_days' => 7,
        'resend_cooldown_seconds' => 60,
        'deliver' => true,
        // True: only the invited email may accept. False: a signed-in user with a
        // different, verified email may accept ("Join as ..."); the invitation
        // records who accepted and that the emails differed.
        'match_email' => true,
    ],

    /*
     * Only read when features.tenant_invitations is true. Authorization is the Gate
     * ability below: define it yourself for full control, or list platform owners'
     * verified emails. With neither, everyone is denied.
     */
    'tenant_invitations' => [
        'ability' => 'concierge.manage-tenants',
        'platform_owners' => [],
        // An email whose invitation was already accepted already has a workspace. Set true
        // to let a platform owner invite it again, which provisions a SECOND workspace.
        'allow_reinvite_after_accept' => false,
        // Requests per minute for the named limiters the host applies to its routes.
        'throttle' => ['inspect' => 30, 'accept' => 10, 'invite' => 20],
    ],

    /*
     * Concierge's own pages and endpoints for managing members and accepting
     * invitations, named concierge.*. concierge:install turns them on; an app
     * whose config has no 'routes' key keeps them off and keeps its own routes.
     * Workspace mode (concierge's engine) gets every route; app-wide mode gets
     * only the invitation pages for now; the kit engine gets none.
     */
    'routes' => [
        'enabled' => false,

        // Put in front of every path, e.g. 'team' gives /team/members.
        'prefix' => '',

        // For the member pages and actions. concierge.member is always added.
        'middleware' => ['web', 'auth'],

        // For the invitation page, which a signed-out visitor opens from the email.
        // Accepting it always needs a signed-in user as well.
        'invitation_middleware' => ['web'],

        // Where Continue goes once an invitation is accepted or can't be.
        'home' => '/',

        // The Inertia components each page renders, as concierge:pages writes them.
        'pages' => [
            'members' => 'Concierge/Members',
            'invite' => 'Concierge/InviteMember',
            'member' => 'Concierge/Member',
            'accept' => 'Concierge/AcceptInvitation',
        ],
    ],

    'recent_authentication_seconds' => null,

    /*
     * Who may create an account through the app's registration form. Modes:
     * open (anyone), domains (an email on one of the domains, or a valid
     * invitation), invitation (a valid invitation for that email) or closed
     * (no one; the register page is a 404). The default is open, so an app that
     * upgrades keeps the registration it had. Any other value counts as closed,
     * and so does domains mode with no domains. With Fortify installed this
     * takes effect on its own; otherwise add the Tey\Concierge\Rules\MayRegister
     * rule to the email field where the app validates registration.
     */
    'registration' => [
        'mode' => env('CONCIERGE_REGISTRATION', 'open'),
        // Comma-separated. Compared case-insensitively on the part after the last "@";
        // a subdomain matches only when it is listed itself.
        'domains' => env('CONCIERGE_REGISTRATION_DOMAINS', ''),
    ],

    /*
     * ForcePasswordReset: a manager locks an account down and the owner receives a
     * reset link. With the `database` session driver the user's session rows are
     * deleted; other drivers rely on Laravel's AuthenticateSession middleware.
     */
    'forced_password_reset' => [
        // The password broker (auth.passwords) that issues the reset link; null means the default.
        'broker' => null,
        // Columns set to null when a reset asks for two-factor to be reset. Fortify's by
        // default; only those present on the user table are cleared, and asking with none
        // present is an error rather than a silent no-op.
        'two_factor_columns' => ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'],
    ],

    /*
     * One-click sign-in as a configured account, for local development only.
     * Off by default; both the application and uncached process APP_ENV must be
     * exactly `local`. Missing APP_ENV fails closed, even with cached config.
     */
    'local_login' => [
        'enabled' => false,

        // With membership enabled, establish the first owner of the app or the
        // oldest workspace if it has none. Read-only teams are unchanged.
        // Never creates a workspace.
        // Only takes effect when local login is available; false grants nothing.
        'first_owner' => true,

        'account' => [
            // Value of the identity column. Required: nothing is available without it.
            'email' => null,
            // Display name for the account if the click has to create it.
            'name' => null,
            // Extra columns for a CREATED account; never the identity or password.
            'attributes' => [],
        ],

        // The column the account is looked up and created by.
        'identity' => 'email',

        // The auth guard to sign in on; null means the application default.
        'guard' => null,

        // Where to go after the click when there is no intended URL; null means '/'.
        'redirect' => null,

        // Requests per minute per IP.
        'throttle' => 10,
    ],
];
