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
    MembersPageProps,
    RoleOption,
    WorkspaceSummary,
} from '../resources/js/types';

export const workspace: WorkspaceSummary = { id: '7', name: 'Teylabs' };

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
    user: { id: '10', name: 'Jasper Tey', email: 'jasper@teylabs.com' },
    status: 'active',
    isOwner: true,
    isSelf: true,
    roles: [{ value: 'owner', label: 'Owner', variant: 'primary' }],
    joinedAt: '2026-08-01T09:00:00Z',
    hasActions: false,
    links: { ...noLinks, show: '/workspaces/7/members/1' },
};

export const admin: MemberRow = {
    id: '2',
    user: { id: '11', name: 'Ada Lovelace', email: 'ada@example.com' },
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
    user: { id: '12', name: 'Grace Hopper', email: 'grace@example.com' },
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
        metadata: { links: { ...noAcceptLinks, ...links } },
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
        unverified: make('unverified', { continue: '/email/verify' }),
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
