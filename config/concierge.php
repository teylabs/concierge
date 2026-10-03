<?php

use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\Ownership;
use Tey\Concierge\Models\TenantInvitation;

return [
    'features' => [
        // Set to false for an app that only wants the local-login feature:
        // no membership bindings, middleware alias, commands or automatic migration.
        'membership' => true,

        // Platform-level tenant invitations: a platform owner invites an email and
        // accepting provisions that person's OWN workspace. Off by default: no table,
        // limiter or Gate definition until true. Set it before publishing migrations.
        'tenant_invitations' => false,
    ],

    'models' => [
        'user' => null,
        'workspace' => null,
        'membership' => Membership::class,
        'ownership' => Ownership::class,
        'invitation' => Invitation::class,
        'tenant_invitation' => TenantInvitation::class,
    ],

    'guard' => null,

    /*
     * Ordered highest rank first. Each role is a global Spatie role row
     * (team NULL); assignments are always scoped to a workspace.
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
    ],

    /*
     * Only read when features.tenant_invitations is true. Authorization is the Gate
     * ability below: define it yourself for full control, or list platform owners'
     * verified emails. With neither, everyone is denied.
     */
    'tenant_invitations' => [
        'ability' => 'concierge.manage-tenants',
        'platform_owners' => [],
        // Requests per minute for the named limiters the host applies to its routes.
        'throttle' => ['inspect' => 30, 'accept' => 10, 'invite' => 20],
    ],

    'recent_authentication_seconds' => null,

    /*
     * One-click sign-in as a configured account, for local development only.
     * Off by default; it only ever works in the `local` environment. It signs the
     * account in and nothing else: no roles, membership or workspace are granted.
     */
    'local_login' => [
        'enabled' => false,

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
