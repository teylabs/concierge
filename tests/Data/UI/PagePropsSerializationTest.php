<?php

use Tey\Concierge\Data\UI\AcceptInvitationLinks;
use Tey\Concierge\Data\UI\AcceptInvitationMetadata;
use Tey\Concierge\Data\UI\AcceptInvitationPageProps;
use Tey\Concierge\Data\UI\InvitationLinks;
use Tey\Concierge\Data\UI\InvitationRow;
use Tey\Concierge\Data\UI\InviteMemberLinks;
use Tey\Concierge\Data\UI\InviteMemberMetadata;
use Tey\Concierge\Data\UI\InviteMemberPageProps;
use Tey\Concierge\Data\UI\InviteMemberPayload;
use Tey\Concierge\Data\UI\LocalLoginOffer;
use Tey\Concierge\Data\UI\MemberGrants;
use Tey\Concierge\Data\UI\MemberGrantsMetadata;
use Tey\Concierge\Data\UI\MemberGrantsPayload;
use Tey\Concierge\Data\UI\MemberLinks;
use Tey\Concierge\Data\UI\MemberPageLinks;
use Tey\Concierge\Data\UI\MemberPageMetadata;
use Tey\Concierge\Data\UI\MemberPageProps;
use Tey\Concierge\Data\UI\MemberRow;
use Tey\Concierge\Data\UI\MembersAbilities;
use Tey\Concierge\Data\UI\MembersLinks;
use Tey\Concierge\Data\UI\MembersMetadata;
use Tey\Concierge\Data\UI\MembersPageProps;
use Tey\Concierge\Data\UI\Pagination;
use Tey\Concierge\Data\UI\RoleBadge;
use Tey\Concierge\Data\UI\RoleOption;
use Tey\Concierge\Data\UI\UserSummary;
use Tey\Concierge\Data\UI\WorkspaceSummary;

/*
 * The page props are the PHP half of the resources/js/types.ts contract. These
 * arrays were recorded from the original spatie/laravel-data implementation, so
 * any change to key order, nesting or null handling fails here.
 */

function serialization_member_row(): MemberRow
{
    return new MemberRow(
        id: '2',
        user: new UserSummary('11', 'Ada Lovelace', 'ada@example.com'),
        status: 'active',
        isOwner: false,
        isSelf: true,
        roles: [new RoleBadge('admin', 'Admin', 'info'), new RoleBadge('member', 'Member', 'neutral')],
        joinedAt: null,
        hasActions: true,
        links: new MemberLinks('/members/2', null, '/members/2/suspend', null, null, null),
    );
}

function serialization_member_row_array(): array
{
    return [
        'id' => '2',
        'user' => ['id' => '11', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        'status' => 'active',
        'isOwner' => false,
        'isSelf' => true,
        'roles' => [
            ['value' => 'admin', 'label' => 'Admin', 'variant' => 'info'],
            ['value' => 'member', 'label' => 'Member', 'variant' => 'neutral'],
        ],
        'joinedAt' => null,
        'hasActions' => true,
        'links' => [
            'show' => '/members/2',
            'updateGrants' => null,
            'suspend' => '/members/2/suspend',
            'reinstate' => null,
            'remove' => null,
            'transferOwnership' => null,
        ],
    ];
}

dataset('page props', [
    'members page' => fn () => [
        new MembersPageProps(
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            members: [serialization_member_row()],
            pagination: new Pagination(2, 3, 25, 61, '/members?page=1', null),
            invitations: [new InvitationRow(
                id: '5',
                email: 'grace@example.com',
                roles: [],
                status: 'pending',
                expiresAt: '2026-10-18T00:00:00Z',
                lastSentAt: null,
                invitedBy: 'Ada Lovelace',
                hasActions: false,
                links: new InvitationLinks(null, '/invitations/5/revoke'),
            )],
            metadata: new MembersMetadata(
                refreshUrl: '/members',
                search: 'ada',
                status: null,
                links: new MembersLinks(null),
                abilities: new MembersAbilities(true, false, true),
            ),
        ),
        [
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'members' => [serialization_member_row_array()],
            'pagination' => [
                'currentPage' => 2, 'lastPage' => 3, 'perPage' => 25, 'total' => 61,
                'previousUrl' => '/members?page=1', 'nextUrl' => null,
            ],
            'invitations' => [[
                'id' => '5',
                'email' => 'grace@example.com',
                'roles' => [],
                'status' => 'pending',
                'expiresAt' => '2026-10-18T00:00:00Z',
                'lastSentAt' => null,
                'invitedBy' => 'Ada Lovelace',
                'hasActions' => false,
                'links' => ['resend' => null, 'revoke' => '/invitations/5/revoke'],
            ]],
            'metadata' => [
                'refreshUrl' => '/members',
                'search' => 'ada',
                'status' => null,
                'links' => ['invite' => null],
                'abilities' => ['invite' => true, 'manageMembers' => false, 'transferOwnership' => true],
            ],
        ],
    ],
    'member page with grants' => fn () => [
        new MemberPageProps(
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            member: serialization_member_row(),
            grants: new MemberGrants(
                payload: new MemberGrantsPayload(['admin', 'member']),
                metadata: new MemberGrantsMetadata('/members/2/grants', [
                    new RoleOption('admin', 'Admin', 'Manages members'),
                    new RoleOption('member', 'Member', null),
                ]),
            ),
            metadata: new MemberPageMetadata(new MemberPageLinks('/members')),
        ),
        [
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'member' => serialization_member_row_array(),
            'grants' => [
                'payload' => ['roles' => ['admin', 'member']],
                'metadata' => [
                    'formUrl' => '/members/2/grants',
                    'roleOptions' => [
                        ['value' => 'admin', 'label' => 'Admin', 'description' => 'Manages members'],
                        ['value' => 'member', 'label' => 'Member', 'description' => null],
                    ],
                ],
            ],
            'metadata' => ['links' => ['back' => '/members']],
        ],
    ],
    'member page without grants' => fn () => [
        new MemberPageProps(
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            member: serialization_member_row(),
            grants: null,
            metadata: new MemberPageMetadata(new MemberPageLinks('/members')),
        ),
        [
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'member' => serialization_member_row_array(),
            'grants' => null,
            'metadata' => ['links' => ['back' => '/members']],
        ],
    ],
    'invite member page' => fn () => [
        new InviteMemberPageProps(
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            payload: new InviteMemberPayload('', []),
            metadata: new InviteMemberMetadata(
                formUrl: '/invitations',
                roleOptions: [new RoleOption('member', 'Member', null)],
                links: new InviteMemberLinks('/members'),
            ),
        ),
        [
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'payload' => ['email' => '', 'roles' => []],
            'metadata' => [
                'formUrl' => '/invitations',
                'roleOptions' => [['value' => 'member', 'label' => 'Member', 'description' => null]],
                'links' => ['cancel' => '/members'],
            ],
        ],
    ],
    'accept invitation page' => fn () => [
        new AcceptInvitationPageProps(
            state: 'ready',
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            email: 'grace@example.com',
            roles: [new RoleBadge('member', 'Member', 'neutral')],
            metadata: new AcceptInvitationMetadata(new AcceptInvitationLinks('/accept', null, null, '/logout', null)),
        ),
        [
            'state' => 'ready',
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'email' => 'grace@example.com',
            'roles' => [['value' => 'member', 'label' => 'Member', 'variant' => 'neutral']],
            'metadata' => ['links' => [
                'accept' => '/accept', 'login' => null, 'register' => null, 'logout' => '/logout', 'continue' => null,
            ]],
        ],
    ],
    'accept invitation page without a workspace' => fn () => [
        new AcceptInvitationPageProps(
            state: 'invalid',
            workspace: null,
            email: null,
            roles: [],
            metadata: new AcceptInvitationMetadata(new AcceptInvitationLinks(null, null, null, null, '/')),
        ),
        [
            'state' => 'invalid',
            'workspace' => null,
            'email' => null,
            'roles' => [],
            'metadata' => ['links' => [
                'accept' => null, 'login' => null, 'register' => null, 'logout' => null, 'continue' => '/',
            ]],
        ],
    ],
    'local login offer' => fn () => [
        new LocalLoginOffer('/local-login', 'dev@example.test'),
        ['url' => '/local-login', 'email' => 'dev@example.test'],
    ],
]);

it('serializes page props to the recorded arrays', function (array $case) {
    [$props, $expected] = $case;

    expect($props->toArray())->toBe($expected)
        ->and(json_encode($props))->toBe(json_encode($expected));
})->with('page props');
