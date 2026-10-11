/**
 * Default English copy. Hosts override any subset through
 * createConcierge({ labels }). Placeholders use {name} syntax.
 */
export const defaultLabels = {
    'members.title': 'Members',
    'members.invite': 'Invite member',
    'members.search': 'Search members',
    'members.searchPlaceholder': 'Name or email',
    'members.status': 'Status',
    'members.status.all': 'All statuses',
    'members.status.active': 'Active',
    'members.status.suspended': 'Suspended',
    'members.status.removed': 'Removed',
    'members.clearFilters': 'Clear filters',
    'members.column.member': 'Member',
    'members.column.roles': 'Roles',
    'members.column.status': 'Status',
    'members.column.joined': 'Joined',
    'members.column.actions': 'Actions',
    'members.empty.title': 'No members yet',
    'members.empty.body':
        'Invite someone to start collaborating in {workspace}.',
    'members.noResults.title': 'No matching members',
    'members.noResults.body': 'Try a different name, email or status.',
    'members.owner': 'Owner',
    'members.you': 'You',
    'members.manage': 'Manage',
    'members.noRoles': 'No roles',
    'members.loading': 'Loading members…',
    'pagination.label': 'Pagination',
    'pagination.summary': 'Page {current} of {last} · {total} total',
    'pagination.previous': 'Previous',
    'pagination.next': 'Next',
    'invitations.title': 'Invitations',
    'invitations.description':
        'Pending and expired invitations for this workspace.',
    'invitations.status.pending': 'Pending',
    'invitations.status.expired': 'Expired',
    'invitations.expires': 'Expires {date}',
    'invitations.expired': 'Expired {date}',
    'invitations.sent': 'Sent {date}',
    'invitations.notSent': 'Not sent yet',
    'invitations.invitedBy': 'Invited by {name}',
    'invitations.resend': 'Resend',
    'invitations.resent': 'Invitation to {email} was resent.',
    'invitations.revoke': 'Revoke',
    'invitations.revoke.title': 'Revoke invitation?',
    'invitations.revoke.body':
        'The invitation link sent to {email} will stop working.',
    'invitations.revoke.confirm': 'Revoke invitation',
    'actions.suspend': 'Suspend',
    'actions.suspend.title': 'Suspend {name}?',
    'actions.suspend.body':
        '{name} immediately loses access to {workspace}, including open sessions. Their roles are kept for reinstatement.',
    'actions.reinstate': 'Reinstate',
    'actions.reinstate.title': 'Reinstate {name}?',
    'actions.reinstate.body':
        '{name} regains access to {workspace} with the roles they currently hold.',
    'actions.remove': 'Remove',
    'actions.remove.title': 'Remove {name} from {workspace}?',
    'actions.remove.body':
        'Their workspace roles are revoked. Their account and anything they created stay intact.',
    'actions.transfer': 'Transfer ownership',
    'actions.transfer.title': 'Transfer ownership to {name}?',
    'actions.transfer.body':
        '{name} becomes the owner of {workspace}. You will become an admin. You will be asked to confirm your password.',
    'actions.transfer.typeName': 'Type {name} to confirm',
    'actions.cancel': 'Cancel',
    'actions.retry': 'Try again',
    'invite.title': 'Invite member',
    'invite.description':
        'Send an invitation to join {workspace}. The link expires automatically.',
    'invite.email': 'Email address',
    'invite.roles': 'Roles',
    'invite.rolesHint': 'Choose at least one role.',
    'invite.noRoles':
        'You are not allowed to grant any roles in this workspace.',
    'invite.submit': 'Send invitation',
    'member.back': 'Back to members',
    'member.details': 'Membership',
    'member.email': 'Email',
    'member.status': 'Status',
    'member.joined': 'Joined',
    'member.notJoined': 'Not joined',
    'member.roles': 'Roles',
    'member.rolesDescription':
        'Roles decide what {name} can do in {workspace}.',
    'member.rolesReadOnly': 'You cannot change the roles of this member.',
    'member.saveRoles': 'Save roles',
    'member.rolesSaved': 'Roles saved.',
    'member.access': 'Access',
    'member.accessDescription': 'Changes apply to {workspace} only.',
    'member.noActions': 'There are no actions available for this member.',
    'users.title': 'Users',
    'users.invite': 'Invite user',
    'users.search': 'Search users',
    'users.searchPlaceholder': 'Name or email',
    'users.role': 'Role',
    'users.role.all': 'All roles',
    'users.status': 'Status',
    'users.status.all': 'All statuses',
    'users.status.active': 'Active',
    'users.status.deactivated': 'Deactivated',
    'users.clearFilters': 'Clear filters',
    'users.column.user': 'User',
    'users.column.roles': 'Roles',
    'users.column.status': 'Status',
    'users.column.joined': 'Joined',
    'users.column.actions': 'Actions',
    'users.empty.title': 'No users yet',
    'users.empty.body': 'Invite someone to start collaborating.',
    'users.noResults.title': 'No matching users',
    'users.noResults.body': 'Try a different name, email, role or status.',
    'users.owner': 'Owner',
    'users.operator': 'Operator',
    'users.you': 'You',
    'users.manage': 'Manage',
    'users.noRoles': 'No roles',
    'users.loading': 'Loading users…',
    'user.back': 'Back to users',
    'user.profile': 'Profile',
    'user.profileDescription': 'The name and email address of this user.',
    'user.name': 'Name',
    'user.email': 'Email',
    'user.saveProfile': 'Save profile',
    'user.profileSaved': 'Profile saved.',
    'user.profileReadOnly': 'You cannot edit the profile of this user.',
    'user.details': 'Account',
    'user.status': 'Status',
    'user.joined': 'Joined',
    'user.notJoined': 'Not joined',
    'user.roles': 'Roles',
    'user.rolesDescription': 'Roles decide what {name} can do in the app.',
    'user.rolesReadOnly': 'You cannot change the roles of this user.',
    'user.saveRoles': 'Save roles',
    'user.rolesSaved': 'Roles saved.',
    'user.access': 'Access',
    'user.accessDescription': 'Removing a user deletes their account.',
    'user.noActions': 'There are no actions available for this user.',
    'userActions.remove': 'Remove',
    'userActions.remove.title': 'Remove {name}?',
    'userActions.remove.body':
        'Their account is deleted and they lose all access. This cannot be undone from here.',
    'inviteUser.title': 'Invite user',
    'inviteUser.description':
        'Send an invitation to join the app. The link expires automatically.',
    'inviteUser.email': 'Email address',
    'inviteUser.roles': 'Roles',
    'inviteUser.rolesHint': 'Choose at least one role.',
    'inviteUser.noRoles': 'You are not allowed to grant any roles.',
    'inviteUser.submit': 'Send invitation',
    'accept.title': 'Join {workspace}',
    'accept.titleUnknown': 'Invitation',
    'accept.invitedAs': 'This invitation is for {email}.',
    'accept.roles': 'You will join with these roles:',
    'accept.ready': 'Accept the invitation to join {workspace}.',
    'accept.accept': 'Accept invitation',
    'accept.sign_in_required':
        'Sign in with {email} to accept this invitation.',
    'accept.register_required':
        'Create an account for {email} to accept this invitation.',
    'accept.wrong_account':
        'You are signed in with a different account. Sign out, then sign in as {email}.',
    'accept.unverified':
        'Verify your email address before accepting this invitation.',
    'accept.expired':
        'This invitation has expired. Ask a workspace admin to send a new one.',
    'accept.revoked':
        'This invitation is no longer valid. Ask a workspace admin for a new one.',
    'accept.invalid':
        'This invitation link is not valid. It may have been replaced by a newer invitation.',
    'accept.accepted': 'You have joined {workspace}.',
    'accept.already_member': 'You are already a member of {workspace}.',
    'accept.suspended':
        'Your membership in {workspace} is suspended, so this invitation cannot be used.',
    'accept.authority_lapsed':
        'This invitation can no longer be accepted because the person who sent it no longer has permission. Ask for a new invitation.',
    'accept.workspace_unavailable':
        '{workspace} is not accepting new members right now. Try again later or contact a workspace admin.',
    'accept.grants_changed':
        'The access this invitation offered has changed since it was sent. Ask a workspace admin to send a new invitation.',
    'accept.app.expired':
        'This invitation has expired. Ask an admin to send a new one.',
    'accept.app.revoked':
        'This invitation is no longer valid. Ask an admin for a new one.',
    'accept.app.grants_changed':
        'The access this invitation offered has changed since it was sent. Ask an admin to send a new invitation.',
    'accept.signIn': 'Sign in',
    'accept.register': 'Create account',
    'accept.signOut': 'Sign out',
    'accept.continue': 'Continue',
    'errors.network':
        'We could not reach the server. Check your connection and try again.',
    'errors.server': 'Something went wrong on our side. Please try again.',
    'errors.forbidden': 'You are not allowed to do that.',
    'errors.expired': 'Your session expired. Reload the page and try again.',
    'errors.validation': 'Please fix the highlighted fields.',
} as const;

export type ConciergeLabelKey = keyof typeof defaultLabels;
export type ConciergeLabels = Partial<Record<ConciergeLabelKey, string>>;

export function translate(
    labels: ConciergeLabels,
    key: ConciergeLabelKey,
    params: Record<string, string | number> = {},
): string {
    const template = labels[key] ?? defaultLabels[key];

    return template.replace(/\{(\w+)\}/g, (match, name: string) =>
        name in params ? String(params[name]) : match,
    );
}
