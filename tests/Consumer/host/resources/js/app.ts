import { createApp, h } from 'vue';
import {
    MembersPage,
    createConcierge,
    type MembersPageProps,
} from '@tey/concierge';
import { createInertiaAdapter } from '@tey/concierge/inertia';
import '../css/app.css';

// A host wrapper would receive these from Inertia page props; the proof only
// needs them to type-check against the package's generated contract.
declare const props: MembersPageProps;

createApp({ render: () => h(MembersPage, props) })
    .use(createConcierge({ adapter: createInertiaAdapter() }))
    .mount('#app');
