import { createApp, h, reactive, type Component } from 'vue';
import {
    AcceptInvitationPage,
    createConcierge,
    InviteMemberPage,
    InviteUserPage,
    MemberPage,
    MembersPage,
    UserPage,
    UsersPage,
} from '../resources/js';
import { createFakeAdapter } from './fakeAdapter';
import { fixtures } from './fixtures';
import './harness.css';

const pages: Record<keyof typeof fixtures, Component> = {
    members: MembersPage,
    invite: InviteMemberPage,
    member: MemberPage,
    accept: AcceptInvitationPage,
    users: UsersPage,
    inviteUser: InviteUserPage,
    user: UserPage,
    acceptApp: AcceptInvitationPage,
};

const query = new URLSearchParams(window.location.search);
const pageName = (query.get('page') ?? 'members') as keyof typeof fixtures;
const fixtureName = query.get('fixture') ?? 'default';
const group = fixtures[pageName] as Record<string, object>;
const fixture = group[fixtureName];

if (!pages[pageName] || !fixture) {
    throw new Error(`Unknown harness page/fixture: ${pageName}/${fixtureName}`);
}

const props = reactive(structuredClone(fixture) as Record<string, unknown>);
const { adapter, control } = createFakeAdapter();

declare global {
    interface Window {
        concierge: typeof control & {
            setProps(next: Record<string, unknown>): void;
        };
    }
}

window.concierge = {
    ...control,
    setProps(next) {
        Object.assign(props, next);
    },
};

createApp({
    render: () =>
        h('main', { class: 'mx-auto max-w-5xl px-4 py-8 sm:px-6' }, [
            h(pages[pageName], props),
        ]),
})
    .use(createConcierge({ adapter, locale: 'en-US' }))
    .mount('#app');
