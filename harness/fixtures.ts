/**
 * Typed fixtures for the browser harness. They are checked against the
 * generated types.ts, so a contract change in src/Data/UI breaks type-checking
 * here before it breaks the pages. Real backend lifecycle is proven by the
 * host integration lane, not by these fixtures.
 */
import type {
    AcceptInvitationLinks,
    AcceptInvitationPageProps,
    InvitationRow,
    InviteMemberPageProps,
    MemberPageProps,
    MemberRow,
    InviteUserPageProps,
    MembersPageProps,
    RoleOption,
    UserPageProps,
    UserRow,
    UsersPageProps,
    UserSummary,
    WorkspaceSummary,
} from '../resources/js/types';

export const workspace: WorkspaceSummary = { id: '7', name: 'Teylabs' };

/** A 1x1 teal square, so a "photo" needs no network. */
export const photo =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%230f766e'/%3E%3C/svg%3E";

const users = {
    jasper: {
        id: '10',
        name: 'Jasper Tey',
        email: 'jasper@teylabs.com',
        avatar: { url: null, initials: 'JT', colour: 'indigo' },
    },
    ada: {
        id: '11',
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        avatar: { url: null, initials: 'AL', colour: 'sky' },
    },
    grace: {
        id: '12',
        name: 'Grace Hopper',
        email: 'grace@example.com',
        avatar: { url: null, initials: 'GH', colour: 'emerald' },
    },
} satisfies Record<string, UserSummary>;

const roleOptions: RoleOption[] = [
    {
        value: 'admin',
        label: 'Admin',
        description: 'Manage members, invitations and presenters.',
    },
    {
        value: 'presenter',
        label: 'Presenter',
        description: 'Create and deliver presentations.',
    },
];

const noLinks = {
    show: null,
    updateGrants: null,
    suspend: null,
    reinstate: null,
    remove: null,
    transferOwnership: null,
};

export const owner: MemberRow = {
    id: '1',
    user: users.jasper,
    status: 'active',
    isOwner: true,
    isSelf: true,
    // Hosts hide the owner role from `roles`; isOwner carries it.
    roles: [],
    joinedAt: '2026-08-01T09:00:00Z',
    hasActions: false,
    links: { ...noLinks, show: '/workspaces/7/members/1' },
};

export const admin: MemberRow = {
    id: '2',
    user: users.ada,
    status: 'active',
    isOwner: false,
    isSelf: false,
    roles: [{ value: 'admin', label: 'Admin', variant: 'info' }],
    joinedAt: '2026-08-12T15:30:00Z',
    hasActions: true,
    links: {
        show: '/workspaces/7/members/2',
        updateGrants: '/workspaces/7/members/2/roles',
        suspend: '/workspaces/7/members/2/suspend',
        reinstate: null,
        remove: '/workspaces/7/members/2',
        transferOwnership: '/workspaces/7/members/2/transfer-ownership',
    },
};

export const suspended: MemberRow = {
    id: '3',
    user: users.grace,
    status: 'suspended',
    isOwner: false,
    isSelf: false,
    roles: [{ value: 'presenter', label: 'Presenter', variant: 'muted' }],
    joinedAt: '2026-09-01T12:00:00Z',
    hasActions: true,
    links: {
        ...noLinks,
        show: '/workspaces/7/members/3',
        reinstate: '/workspaces/7/members/3/reinstate',
        remove: '/workspaces/7/members/3',
    },
};

export const pending: InvitationRow = {
    id: '01JAB0000000000000000000PX',
    email: 'linus@example.com',
    roles: [{ value: 'presenter', label: 'Presenter', variant: 'muted' }],
    status: 'pending',
    expiresAt: '2026-09-27T10:00:00Z',
    lastSentAt: '2026-09-20T10:00:00Z',
    invitedBy: 'Jasper Tey',
    hasActions: true,
    links: {
        resend: '/workspaces/7/invitations/01JAB0000000000000000000PX/resend',
        revoke: '/workspaces/7/invitations/01JAB0000000000000000000PX',
    },
};

export const expired: InvitationRow = {
    id: '01JAB0000000000000000000EX',
    email: 'margaret@example.com',
    roles: [{ value: 'admin', label: 'Admin', variant: 'info' }],
    status: 'expired',
    expiresAt: '2026-09-10T10:00:00Z',
    lastSentAt: null,
    invitedBy: null,
    hasActions: true,
    links: {
        resend: '/workspaces/7/invitations/01JAB0000000000000000000EX/resend',
        revoke: null,
    },
};

const membersBase: MembersPageProps = {
    workspace,
    members: [owner, admin, suspended],
    pagination: {
        currentPage: 1,
        lastPage: 2,
        perPage: 3,
        total: 5,
        previousUrl: null,
        nextUrl: '/workspaces/7/members?page=2',
    },
    invitations: [pending, expired],
    metadata: {
        refreshUrl: '/workspaces/7/members',
        search: '',
        status: null,
        links: { invite: '/workspaces/7/invitations/create' },
        abilities: {
            invite: true,
            manageMembers: true,
            transferOwnership: true,
        },
    },
};

/** Viewer is a presenter: every action link is null. */
const membersReadOnly: MembersPageProps = {
    ...membersBase,
    members: membersBase.members.map((member) => ({
        ...member,
        isSelf: member.id === '3',
        hasActions: false,
        links: { ...noLinks },
    })),
    invitations: [],
    metadata: {
        ...membersBase.metadata,
        links: { invite: null },
        abilities: {
            invite: false,
            manageMembers: false,
            transferOwnership: false,
        },
    },
};

const noUserLinks = {
    show: null,
    updateProfile: null,
    updateGrants: null,
    remove: null,
    deactivate: null,
    restore: null,
};

export const appOwner: UserRow = {
    id: '10',
    user: users.jasper,
    status: 'active',
    isOwner: true,
    isSelf: true,
    isOperator: false,
    roles: [],
    joinedAt: '2026-08-01T09:00:00Z',
    hasActions: false,
    links: { ...noUserLinks, show: '/users/10' },
    columns: { plan: 'Team', seats: 5 },
};

export const appAdmin: UserRow = {
    id: '11',
    user: users.ada,
    status: 'active',
    isOwner: false,
    isSelf: false,
    isOperator: false,
    roles: [{ value: 'admin', label: 'Admin', variant: 'info' }],
    joinedAt: '2026-08-12T15:30:00Z',
    hasActions: true,
    links: {
        show: '/users/11',
        updateProfile: '/users/11',
        updateGrants: '/users/11/roles',
        remove: '/users/11',
        deactivate: '/users/11/deactivate',
        restore: null,
    },
    columns: { plan: 'Pro', seats: 2 },
};

export const deactivated: UserRow = {
    id: '12',
    user: users.grace,
    status: 'deactivated',
    isOwner: false,
    isSelf: false,
    isOperator: false,
    roles: [{ value: 'presenter', label: 'Presenter', variant: 'muted' }],
    joinedAt: '2026-09-01T12:00:00Z',
    hasActions: true,
    links: {
        ...noUserLinks,
        show: '/users/12',
        remove: '/users/12',
        restore: '/users/12/restore',
    },
    columns: { plan: null, seats: 0 },
};

const usersBase: UsersPageProps = {
    users: [appOwner, appAdmin],
    pagination: {
        currentPage: 1,
        lastPage: 2,
        perPage: 3,
        total: 5,
        previousUrl: null,
        nextUrl: '/users?page=2',
    },
    metadata: {
        refreshUrl: '/users',
        search: '',
        role: null,
        status: null,
        roleOptions,
        columns: [],
        links: { invite: '/users/create' },
        abilities: { invite: true, manageUsers: true },
    },
};

const userGrants: NonNullable<UserPageProps['grants']> = {
    payload: { roles: ['admin'] },
    metadata: { formUrl: '/users/11/roles', roleOptions },
};

const userProfile: NonNullable<UserPageProps['profile']> = {
    payload: { name: 'Ada Lovelace', email: 'ada@example.com' },
    metadata: { formUrl: '/users/11' },
};

const singlePage = {
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    total: 0,
    previousUrl: null,
    nextUrl: null,
};

export const fixtures = {
    members: {
        default: membersBase,
        readOnly: membersReadOnly,
        roleless: {
            ...membersBase,
            members: [owner, { ...admin, roles: [] }],
        },
        /** The app supplies photos: one that loads and one that does not. */
        photos: {
            ...membersBase,
            members: [
                {
                    ...owner,
                    user: {
                        ...users.jasper,
                        avatar: { ...users.jasper.avatar, url: photo },
                    },
                },
                {
                    ...admin,
                    user: {
                        ...users.ada,
                        avatar: {
                            ...users.ada.avatar,
                            url: '/photos/missing.png',
                        },
                    },
                },
                suspended,
            ],
            invitations: [],
        },
        empty: {
            ...membersBase,
            members: [owner],
            invitations: [],
            pagination: { ...singlePage, total: 1 },
        },
        noResults: {
            ...membersBase,
            members: [],
            invitations: [],
            pagination: singlePage,
            metadata: { ...membersBase.metadata, search: 'nobody' },
        },
    } satisfies Record<string, MembersPageProps>,
    invite: {
        default: {
            workspace,
            payload: { email: '', roles: [] },
            metadata: {
                formUrl: '/workspaces/7/invitations',
                roleOptions,
                links: { cancel: '/workspaces/7/members' },
            },
        },
        noGrantableRoles: {
            workspace,
            payload: { email: '', roles: [] },
            metadata: {
                formUrl: '/workspaces/7/invitations',
                roleOptions: [],
                links: { cancel: '/workspaces/7/members' },
            },
        },
    } satisfies Record<string, InviteMemberPageProps>,
    member: {
        default: {
            workspace,
            member: admin,
            grants: {
                payload: { roles: ['admin'] },
                metadata: {
                    formUrl: '/workspaces/7/members/2/roles',
                    roleOptions,
                },
            },
            metadata: { links: { back: '/workspaces/7/members' } },
        },
        readOnly: {
            workspace,
            member: { ...owner, isSelf: false },
            grants: null,
            metadata: { links: { back: '/workspaces/7/members' } },
        },
    } satisfies Record<string, MemberPageProps>,
    accept: acceptFixtures(),
    users: {
        default: usersBase,
        /** The status filter is on "all": deactivated users show too. */
        withDeactivated: {
            ...usersBase,
            users: [appOwner, appAdmin, deactivated],
            metadata: { ...usersBase.metadata, status: 'all' },
        },
        /** Viewer is a presenter: every action link is null. */
        readOnly: {
            ...usersBase,
            users: usersBase.users.map((user) => ({
                ...user,
                hasActions: false,
                links: { ...noUserLinks },
            })),
            metadata: {
                ...usersBase.metadata,
                links: { invite: null },
                abilities: { invite: false, manageUsers: false },
            },
        },
        /** A shielded account, such as a platform operator. */
        operator: {
            ...usersBase,
            users: [
                {
                    ...appAdmin,
                    isOperator: true,
                    hasActions: false,
                    links: { ...noUserLinks, show: '/users/11' },
                },
                appOwner,
            ],
        },
        /** The app added its own columns through Concierge::userColumns(). */
        columns: {
            ...usersBase,
            users: [appOwner, appAdmin, deactivated],
            metadata: {
                ...usersBase.metadata,
                columns: [
                    { key: 'plan', label: 'Plan' },
                    { key: 'seats', label: 'Seats' },
                ],
            },
        },
        filtered: {
            ...usersBase,
            users: [deactivated],
            pagination: { ...singlePage, total: 1 },
            metadata: {
                ...usersBase.metadata,
                role: 'presenter',
                status: 'deactivated',
            },
        },
        empty: {
            ...usersBase,
            users: [],
            pagination: singlePage,
        },
        noResults: {
            ...usersBase,
            users: [],
            pagination: singlePage,
            metadata: { ...usersBase.metadata, search: 'nobody' },
        },
    } satisfies Record<string, UsersPageProps>,
    inviteUser: {
        default: {
            payload: { email: '', roles: [] },
            metadata: {
                formUrl: '/users/invitations',
                roleOptions,
                links: { cancel: '/users' },
            },
        },
        noGrantableRoles: {
            payload: { email: '', roles: [] },
            metadata: {
                formUrl: '/users/invitations',
                roleOptions: [],
                links: { cancel: '/users' },
            },
        },
    } satisfies Record<string, InviteUserPageProps>,
    user: {
        default: {
            user: appAdmin,
            profile: userProfile,
            grants: userGrants,
            metadata: { links: { back: '/users' } },
        },
        /** A deactivated user, with a restore link and no deactivate link. */
        deactivated: {
            user: deactivated,
            profile: userProfile,
            grants: userGrants,
            metadata: { links: { back: '/users' } },
        },
        readOnly: {
            user: { ...appOwner, isSelf: false },
            profile: null,
            grants: null,
            metadata: { links: { back: '/users' } },
        },
    } satisfies Record<string, UserPageProps>,
    /** App-wide invitations: no workspace, so the page names the app. */
    acceptApp: acceptAppFixtures(),
};

function acceptFixtures(): Record<
    AcceptInvitationPageProps['state'],
    AcceptInvitationPageProps
> {
    const noAcceptLinks: AcceptInvitationLinks = {
        accept: null,
        login: null,
        register: null,
        logout: null,
        continue: null,
    };
    const base = {
        workspace,
        email: 'linus@example.com',
        roles: [{ value: 'presenter', label: 'Presenter', variant: 'muted' }],
    };
    const make = (
        state: AcceptInvitationPageProps['state'],
        links: Partial<AcceptInvitationLinks> = {},
        overrides: Partial<AcceptInvitationPageProps> = {},
    ): AcceptInvitationPageProps => ({
        ...base,
        state,
        metadata: { links: { ...noAcceptLinks, ...links }, appName: 'Teylabs' },
        ...overrides,
    });

    return {
        ready: make('ready', { accept: '/invitations/opaque-token/accept' }),
        sign_in_required: make('sign_in_required', {
            login: '/login',
            register: '/register',
        }),
        register_required: make('register_required', {
            register: '/register',
            login: '/login',
        }),
        wrong_account: make('wrong_account', { logout: '/logout' }),
        unverified: make('unverified', {
            continue: '/email/verify',
            logout: '/logout',
        }),
        expired: make('expired', { continue: '/' }),
        revoked: make('revoked', { continue: '/' }),
        invalid: make(
            'invalid',
            { continue: '/' },
            { workspace: null, email: null, roles: [] },
        ),
        accepted: make('accepted', { continue: '/workspaces/7' }),
        already_member: make('already_member', { continue: '/workspaces/7' }),
        suspended: make('suspended', { continue: '/' }),
        authority_lapsed: make('authority_lapsed', { continue: '/' }),
        // A stale accept link must still not render outside the ready state.
        workspace_unavailable: make('workspace_unavailable', {
            continue: '/',
            accept: '/invitations/opaque-token/accept',
        }),
        grants_changed: make('grants_changed', {
            continue: '/',
            accept: '/invitations/opaque-token/accept',
        }),
    };
}

function acceptAppFixtures(): Record<
    'ready' | 'expired' | 'invalid' | 'readyNoName' | 'expiredNoName',
    AcceptInvitationPageProps
> {
    const links = {
        accept: null,
        login: null,
        register: null,
        logout: null,
        continue: null,
    };
    const base = {
        workspace: null,
        email: 'linus@example.com',
        roles: [{ value: 'presenter', label: 'Presenter', variant: 'muted' }],
    };

    return {
        ready: {
            ...base,
            state: 'ready',
            metadata: {
                links: { ...links, accept: '/invitations/opaque-token/accept' },
                appName: 'Teylabs',
            },
        },
        expired: {
            ...base,
            state: 'expired',
            metadata: {
                links: { ...links, continue: '/' },
                appName: 'Teylabs',
            },
        },
        /** An app that does not pass `appName`: generic wording. */
        readyNoName: {
            ...base,
            state: 'ready',
            metadata: {
                links: { ...links, accept: '/invitations/opaque-token/accept' },
                appName: null,
            },
        },
        expiredNoName: {
            ...base,
            state: 'expired',
            metadata: { links: { ...links, continue: '/' }, appName: null },
        },
        invalid: {
            ...base,
            state: 'invalid',
            email: null,
            roles: [],
            metadata: {
                links: { ...links, continue: '/' },
                appName: 'Teylabs',
            },
        },
    };
}
