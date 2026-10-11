export type AcceptInvitationLinks = {
accept: string | null,
login: string | null,
register: string | null,
logout: string | null,
continue: string | null,
};
export type AcceptInvitationMetadata = {
links: AcceptInvitationLinks,
};
export type AcceptInvitationPageProps = {
state: 'ready' | 'sign_in_required' | 'register_required' | 'wrong_account' | 'unverified' | 'expired' | 'revoked' | 'invalid' | 'accepted' | 'already_member' | 'suspended' | 'authority_lapsed' | 'workspace_unavailable' | 'grants_changed',
workspace: WorkspaceSummary | null,
email: string | null,
roles: RoleBadge[],
metadata: AcceptInvitationMetadata,
};
export type CursorPaginatedDataCollection<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type InvitationLinks = {
resend: string | null,
revoke: string | null,
};
export type InvitationRow = {
id: string,
email: string,
roles: RoleBadge[],
status: string,
expiresAt: string,
lastSentAt: string | null,
invitedBy: string | null,
hasActions: boolean,
links: InvitationLinks,
};
export type InviteMemberLinks = {
cancel: string,
};
export type InviteMemberMetadata = {
formUrl: string,
roleOptions: RoleOption[],
links: InviteMemberLinks,
};
export type InviteMemberPageProps = {
workspace: WorkspaceSummary,
payload: InviteMemberPayload,
metadata: InviteMemberMetadata,
};
export type InviteMemberPayload = {
email: string,
roles: string[],
};
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type LocalLoginOffer = {
url: string,
email: string,
};
export type MemberGrants = {
payload: MemberGrantsPayload,
metadata: MemberGrantsMetadata,
};
export type MemberGrantsMetadata = {
formUrl: string,
roleOptions: RoleOption[],
};
export type MemberGrantsPayload = {
roles: string[],
};
export type MemberLinks = {
show: string | null,
updateGrants: string | null,
suspend: string | null,
reinstate: string | null,
remove: string | null,
transferOwnership: string | null,
};
export type MemberPageLinks = {
back: string,
};
export type MemberPageMetadata = {
links: MemberPageLinks,
};
export type MemberPageProps = {
workspace: WorkspaceSummary,
member: MemberRow,
grants: MemberGrants | null,
metadata: MemberPageMetadata,
};
export type MemberRow = {
id: string,
user: UserSummary,
status: 'active' | 'suspended' | 'removed',
isOwner: boolean,
isSelf: boolean,
roles: RoleBadge[],
joinedAt: string | null,
hasActions: boolean,
links: MemberLinks,
};
export type MembersAbilities = {
invite: boolean,
manageMembers: boolean,
transferOwnership: boolean,
};
export type MembersLinks = {
invite: string | null,
};
export type MembersMetadata = {
refreshUrl: string,
search: string,
status: string | null,
links: MembersLinks,
abilities: MembersAbilities,
};
export type MembersPageProps = {
workspace: WorkspaceSummary,
members: MemberRow[],
pagination: Pagination,
invitations: InvitationRow[],
metadata: MembersMetadata,
};
export type PaginatedDataCollection<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type Pagination = {
currentPage: number,
lastPage: number,
perPage: number,
total: number,
previousUrl: string | null,
nextUrl: string | null,
};
export type RoleBadge = {
value: string,
label: string,
variant: string,
};
export type RoleOption = {
value: string,
label: string,
description: string | null,
};
export type UserAvatar = {
url: string | null,
initials: string,
colour: string,
};
export type UserSummary = {
id: string,
name: string,
email: string,
avatar: UserAvatar,
};
export type WorkspaceSummary = {
id: string,
name: string,
};
