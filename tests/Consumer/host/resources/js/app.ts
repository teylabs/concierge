import { createApp, h } from 'vue';
import {
    LocalLoginButton,
    MembersPage,
    createConcierge,
    type LocalLoginOffer,
    type MembersPageProps,
} from '@tey/concierge';
import { createInertiaAdapter } from '@tey/concierge/inertia';
import '../css/app.css';

// A host wrapper would receive these from Inertia page props; the proof only
// needs them to type-check against the package's generated contract.
declare const props: MembersPageProps;
declare const localLogin: LocalLoginOffer | null;
declare const csrfToken: string;

createApp({
    render: () => [
        // The button is a plain form: it needs neither the plugin nor Inertia.
        h(LocalLoginButton, { offer: localLogin, csrfToken }),
        h(MembersPage, props),
    ],
})
    .use(createConcierge({ adapter: createInertiaAdapter() }))
    .mount('#app');
