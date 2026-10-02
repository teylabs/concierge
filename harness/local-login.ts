import { createApp, h } from 'vue';
import { LocalLoginButton } from '../resources/js';
import './harness.css';

// The local-login button is a plain form, not a page: it needs no adapter and no
// plugin, so it mounts on its own, with or without an offer (`?offer=none`).
const offer =
    new URLSearchParams(window.location.search).get('offer') === 'none'
        ? null
        : { url: '/local-login', email: 'local@example.test' };

createApp({
    render: () =>
        h('main', { class: 'mx-auto max-w-sm px-4 py-8' }, [
            h(LocalLoginButton, { offer, csrfToken: 'harness-token' }),
        ]),
}).mount('#app');
