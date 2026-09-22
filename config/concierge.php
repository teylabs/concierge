<?php

use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\Ownership;

return [
    'models' => [
        'user' => null,
        'workspace' => null,
        'membership' => Membership::class,
        'ownership' => Ownership::class,
        'invitation' => Invitation::class,
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

    'recent_authentication_seconds' => null,
];
