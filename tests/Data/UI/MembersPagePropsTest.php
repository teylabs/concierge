<?php

use Spatie\LaravelData\LaravelDataServiceProvider;
use Tey\Concierge\Data\UI\MembersPageProps;

beforeEach(function () {
    $this->app->register(LaravelDataServiceProvider::class);
});

it('serializes the members page contract with camelCase keys and nested lists', function () {
    $props = MembersPageProps::from([
        'workspace' => ['id' => '7', 'name' => 'Teylabs'],
        'members' => [[
            'id' => '2',
            'user' => ['id' => '11', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            'status' => 'active',
            'isOwner' => false,
            'isSelf' => false,
            'roles' => [['value' => 'admin', 'label' => 'Admin', 'variant' => 'info']],
            'joinedAt' => '2026-08-12T15:30:00Z',
            'hasActions' => true,
            'links' => [
                'show' => '/workspaces/7/members/2',
                'updateGrants' => null,
                'suspend' => '/workspaces/7/members/2/suspend',
                'reinstate' => null,
                'remove' => null,
                'transferOwnership' => null,
            ],
        ]],
        'pagination' => [
            'currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 1,
            'previousUrl' => null, 'nextUrl' => null,
        ],
        'invitations' => [],
        'metadata' => [
            'refreshUrl' => '/workspaces/7/members',
            'search' => '',
            'status' => null,
            'links' => ['invite' => null],
            'abilities' => ['invite' => false, 'manageMembers' => true, 'transferOwnership' => false],
        ],
    ])->toArray();

    expect($props)->toHaveKeys(['workspace', 'members', 'pagination', 'invitations', 'metadata'])
        ->and(array_keys($props['members'][0]))->toBe([
            'id', 'user', 'status', 'isOwner', 'isSelf', 'roles', 'joinedAt', 'hasActions', 'links',
        ])
        ->and($props['members'][0]['roles'])->toBe([['value' => 'admin', 'label' => 'Admin', 'variant' => 'info']])
        ->and($props['members'][0]['links']['reinstate'])->toBeNull()
        ->and($props['metadata']['abilities'])->toBe(['invite' => false, 'manageMembers' => true, 'transferOwnership' => false]);
});
