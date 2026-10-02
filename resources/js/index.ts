export { default as LocalLoginButton } from './LocalLoginButton.vue';
export { default as AcceptInvitationPage } from './pages/AcceptInvitationPage.vue';
export { default as InviteMemberPage } from './pages/InviteMemberPage.vue';
export { default as MemberPage } from './pages/MemberPage.vue';
export { default as MembersPage } from './pages/MembersPage.vue';

export {
    createConcierge,
    useConcierge,
    type ConciergeContext,
    type ConciergeOptions,
} from './plugin';
export type {
    ConciergeAdapter,
    ConciergeFailure,
    ConciergeMethod,
    ConciergeSubmitRequest,
    ConciergeSubmitResult,
    ConciergeVisitOptions,
} from './adapter';
export {
    defaultLabels,
    type ConciergeLabelKey,
    type ConciergeLabels,
} from './labels';
export type * from './types';
