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
use Tey\Concierge\Data\UI\InviteUserLinks;
use Tey\Concierge\Data\UI\InviteUserMetadata;
use Tey\Concierge\Data\UI\InviteUserPageProps;
use Tey\Concierge\Data\UI\InviteUserPayload;
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
use Tey\Concierge\Data\UI\MembersTab;
use Tey\Concierge\Data\UI\MembersTable;
use Tey\Concierge\Data\UI\Pagination;
use Tey\Concierge\Data\UI\RoleBadge;
use Tey\Concierge\Data\UI\RoleOption;
use Tey\Concierge\Data\UI\RowAction;
use Tey\Concierge\Data\UI\RowActionConfirm;
use Tey\Concierge\Data\UI\TableColumn;
use Tey\Concierge\Data\UI\TablePagination;
use Tey\Concierge\Data\UI\TableSort;
use Tey\Concierge\Data\UI\UserAvatar;
use Tey\Concierge\Data\UI\UserColumn;
use Tey\Concierge\Data\UI\UserGrants;
use Tey\Concierge\Data\UI\UserGrantsMetadata;
use Tey\Concierge\Data\UI\UserGrantsPayload;
use Tey\Concierge\Data\UI\UserLinks;
use Tey\Concierge\Data\UI\UserPageLinks;
use Tey\Concierge\Data\UI\UserPageMetadata;
use Tey\Concierge\Data\UI\UserPageProps;
use Tey\Concierge\Data\UI\UserProfile;
use Tey\Concierge\Data\UI\UserProfileMetadata;
use Tey\Concierge\Data\UI\UserProfilePayload;
use Tey\Concierge\Data\UI\UserRow;
use Tey\Concierge\Data\UI\UsersAbilities;
use Tey\Concierge\Data\UI\UsersLinks;
use Tey\Concierge\Data\UI\UsersMetadata;
use Tey\Concierge\Data\UI\UsersPageProps;
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
        'user' => [
            'id' => '11',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'avatar' => ['url' => null, 'initials' => 'AL', 'colour' => 'sky'],
        ],
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
        'actions' => [],
        'acceptedWithOtherEmail' => false,
        'invitedEmail' => null,
    ];
}

function serialization_user_row(): UserRow
{
    return new UserRow(
        id: '11',
        user: new UserSummary('11', 'Ada Lovelace', 'ada@example.com'),
        status: 'deactivated',
        isOwner: false,
        isSelf: false,
        isOperator: true,
        roles: [new RoleBadge('admin', 'Admin', 'info')],
        joinedAt: '2026-08-12T15:30:00Z',
        hasActions: true,
        links: new UserLinks('/users/11', '/users/11', null, '/users/11', null, '/users/11/restore', '/users/11/force-password-reset'),
        twoFactorResettable: true,
        columns: ['plan' => 'Pro', 'seats' => 3, 'beta' => false, 'team' => null],
    );
}

function serialization_user_row_array(): array
{
    return [
        'id' => '11',
        'user' => [
            'id' => '11',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'avatar' => ['url' => null, 'initials' => 'AL', 'colour' => 'sky'],
        ],
        'status' => 'deactivated',
        'isOwner' => false,
        'isSelf' => false,
        'isOperator' => true,
        'roles' => [['value' => 'admin', 'label' => 'Admin', 'variant' => 'info']],
        'joinedAt' => '2026-08-12T15:30:00Z',
        'hasActions' => true,
        'links' => ['show' => '/users/11', 'updateProfile' => '/users/11', 'updateGrants' => null, 'remove' => '/users/11', 'deactivate' => null, 'restore' => '/users/11/restore', 'forcePasswordReset' => '/users/11/force-password-reset'],
        'twoFactorResettable' => true,
        'columns' => ['plan' => 'Pro', 'seats' => 3, 'beta' => false, 'team' => null],
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
                'actions' => [],
                'message' => null,
            ]],
            'metadata' => [
                'refreshUrl' => '/members',
                'search' => 'ada',
                'status' => null,
                'links' => ['invite' => null],
                'abilities' => ['invite' => true, 'manageMembers' => false, 'transferOwnership' => true],
            ],
            'tab' => 'members',
            'tabs' => [],
            'table' => null,
        ],
    ],
    'members page with a table' => fn () => [
        new MembersPageProps(
            workspace: new WorkspaceSummary('7', 'Teylabs'),
            members: [],
            pagination: new Pagination(1, 1, 25, 0, null, null),
            invitations: [],
            metadata: new MembersMetadata('/members', '', null, new MembersLinks(null), new MembersAbilities(false, false, false)),
            tab: 'invitations',
            tabs: [new MembersTab('members', 'Members', '/members', 4, false), new MembersTab('invitations', 'Invitations', '/members?tab=invitations', 0, true)],
            table: new MembersTable(
                rows: [],
                columns: [new TableColumn('email', 'Email', sortable: true), new TableColumn('actions', 'Actions', align: 'end', srOnlyLabel: true)],
                sort: new TableSort('email', 'desc'),
                search: null,
                filters: ['role' => 'admin'],
                tabs: [new MembersTab('members', 'Members', '/members', 4, false)],
                pagination: new TablePagination(1, 25, 0, null, null, 1),
                emptyState: 'first-use',
            ),
        ),
        [
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'members' => [],
            'pagination' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 0, 'previousUrl' => null, 'nextUrl' => null],
            'invitations' => [],
            'metadata' => [
                'refreshUrl' => '/members',
                'search' => '',
                'status' => null,
                'links' => ['invite' => null],
                'abilities' => ['invite' => false, 'manageMembers' => false, 'transferOwnership' => false],
            ],
            'tab' => 'invitations',
            'tabs' => [
                ['key' => 'members', 'label' => 'Members', 'url' => '/members', 'count' => 4, 'current' => false],
                ['key' => 'invitations', 'label' => 'Invitations', 'url' => '/members?tab=invitations', 'count' => 0, 'current' => true],
            ],
            'table' => [
                'rows' => [],
                'columns' => [
                    ['key' => 'email', 'label' => 'Email', 'sortable' => true, 'hideable' => false, 'hiddenByDefault' => false, 'align' => null, 'srOnlyLabel' => false],
                    ['key' => 'actions', 'label' => 'Actions', 'sortable' => false, 'hideable' => false, 'hiddenByDefault' => false, 'align' => 'end', 'srOnlyLabel' => true],
                ],
                'sort' => ['column' => 'email', 'direction' => 'desc'],
                'search' => null,
                'filters' => ['role' => 'admin'],
                'tabs' => [['key' => 'members', 'label' => 'Members', 'url' => '/members', 'count' => 4, 'current' => false]],
                'pagination' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'from' => null, 'to' => null, 'lastPage' => 1],
                'emptyState' => 'first-use',
            ],
        ],
    ],
    'row with actions' => fn () => [
        new MemberRow(
            id: '9',
            user: new UserSummary('9', 'Grace Hopper', 'grace@example.com'),
            status: 'active',
            isOwner: false,
            isSelf: false,
            roles: [],
            joinedAt: null,
            hasActions: true,
            links: new MemberLinks(null, null, null, null, '/members/9', null),
            actions: [new RowAction('remove', 'Remove', '/members/9', 'delete', true, new RowActionConfirm('Remove Grace?', 'They lose access.', 'Remove member'))],
        ),
        [
            'id' => '9',
            'user' => ['id' => '9', 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'avatar' => ['url' => null, 'initials' => 'GH', 'colour' => UserAvatar::colour('9')]],
            'status' => 'active',
            'isOwner' => false,
            'isSelf' => false,
            'roles' => [],
            'joinedAt' => null,
            'hasActions' => true,
            'links' => ['show' => null, 'updateGrants' => null, 'suspend' => null, 'reinstate' => null, 'remove' => '/members/9', 'transferOwnership' => null],
            'actions' => [['key' => 'remove', 'label' => 'Remove', 'url' => '/members/9', 'method' => 'delete', 'destructive' => true, 'confirm' => ['title' => 'Remove Grace?', 'body' => 'They lose access.', 'confirmLabel' => 'Remove member']]],
            'acceptedWithOtherEmail' => false,
            'invitedEmail' => null,
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
            metadata: new AcceptInvitationMetadata(new AcceptInvitationLinks('/accept', null, null, '/logout', null), 'Teylabs'),
        ),
        [
            'state' => 'ready',
            'workspace' => ['id' => '7', 'name' => 'Teylabs'],
            'email' => 'grace@example.com',
            'roles' => [['value' => 'member', 'label' => 'Member', 'variant' => 'neutral']],
            'metadata' => ['links' => [
                'accept' => '/accept', 'login' => null, 'register' => null, 'logout' => '/logout', 'continue' => null, 'switchAccount' => null, 'cancel' => null,
            ], 'appName' => 'Teylabs'],
            'signedInAs' => null,
            'otherEmail' => false,
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
                'accept' => null, 'login' => null, 'register' => null, 'logout' => null, 'continue' => '/', 'switchAccount' => null, 'cancel' => null,
            ], 'appName' => null],
            'signedInAs' => null,
            'otherEmail' => false,
        ],
    ],
    'users page' => fn () => [
        new UsersPageProps(
            users: [serialization_user_row()],
            pagination: new Pagination(1, 1, 25, 1, null, null),
            metadata: new UsersMetadata(
                refreshUrl: '/users',
                search: 'ada',
                role: 'admin',
                status: 'deactivated',
                roleOptions: [new RoleOption('admin', 'Admin', null)],
                columns: [new UserColumn('plan', 'Plan')],
                links: new UsersLinks('/users/create'),
                abilities: new UsersAbilities(true, false),
            ),
        ),
        [
            'users' => [serialization_user_row_array()],
            'pagination' => [
                'currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 1,
                'previousUrl' => null, 'nextUrl' => null,
            ],
            'metadata' => [
                'refreshUrl' => '/users',
                'search' => 'ada',
                'role' => 'admin',
                'status' => 'deactivated',
                'roleOptions' => [['value' => 'admin', 'label' => 'Admin', 'description' => null]],
                'columns' => [['key' => 'plan', 'label' => 'Plan']],
                'links' => ['invite' => '/users/create'],
                'abilities' => ['invite' => true, 'manageUsers' => false],
            ],
        ],
    ],
    'user page with profile and grants' => fn () => [
        new UserPageProps(
            user: serialization_user_row(),
            profile: new UserProfile(
                payload: new UserProfilePayload('Ada Lovelace', 'ada@example.com'),
                metadata: new UserProfileMetadata('/users/11'),
            ),
            grants: new UserGrants(
                payload: new UserGrantsPayload(['admin']),
                metadata: new UserGrantsMetadata('/users/11/roles', [new RoleOption('admin', 'Admin', 'Manages users')]),
            ),
            metadata: new UserPageMetadata(new UserPageLinks('/users')),
        ),
        [
            'user' => serialization_user_row_array(),
            'profile' => [
                'payload' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
                'metadata' => ['formUrl' => '/users/11'],
            ],
            'grants' => [
                'payload' => ['roles' => ['admin']],
                'metadata' => [
                    'formUrl' => '/users/11/roles',
                    'roleOptions' => [['value' => 'admin', 'label' => 'Admin', 'description' => 'Manages users']],
                ],
            ],
            'metadata' => ['links' => ['back' => '/users']],
        ],
    ],
    'user page without profile or grants' => fn () => [
        new UserPageProps(
            user: serialization_user_row(),
            profile: null,
            grants: null,
            metadata: new UserPageMetadata(new UserPageLinks('/users')),
        ),
        [
            'user' => serialization_user_row_array(),
            'profile' => null,
            'grants' => null,
            'metadata' => ['links' => ['back' => '/users']],
        ],
    ],
    'invite user page' => fn () => [
        new InviteUserPageProps(
            payload: new InviteUserPayload('', []),
            metadata: new InviteUserMetadata(
                formUrl: '/users/invitations',
                roleOptions: [new RoleOption('member', 'Member', null)],
                links: new InviteUserLinks('/users'),
            ),
        ),
        [
            'payload' => ['email' => '', 'roles' => []],
            'metadata' => [
                'formUrl' => '/users/invitations',
                'roleOptions' => [['value' => 'member', 'label' => 'Member', 'description' => null]],
                'links' => ['cancel' => '/users'],
            ],
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
