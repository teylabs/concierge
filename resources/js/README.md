# Concierge Vue UI

Optional, reusable Vue 3 pages for Concierge membership administration, plus a
local-login button that works without them. The PHP
core never depends on this directory or on Inertia.

## Exports

`@tey/concierge` (this directory's `index.ts`):

- Pages: `MembersPage`, `InviteMemberPage`, `MemberPage`, `AcceptInvitationPage`.
  Each takes the matching generated `*PageProps` type as its props.
- `createConcierge({ adapter, labels?, locale? })`: a Vue plugin that every page requires.
- `LocalLoginButton`: a plain form for one-click local sign-in. It needs no
  plugin and no Inertia. See [Local login button](#local-login-button).
- Types: `ConciergeAdapter` and friends, `defaultLabels`, and every generated
  contract type from `types.ts`.

`@tey/concierge/inertia` exports `createInertiaAdapter()`. It is the only module
that imports `@inertiajs/vue3`.

Styles: `resources/css/concierge.css`.

## Host integration

1. **Vite.** Alias the Composer-installed sources, and dedupe framework
   packages so vendor files resolve the host's copies:

    ```ts
    resolve: {
        alias: { '@tey/concierge': path.resolve('vendor/tey/concierge/resources/js') },
        dedupe: ['vue', '@inertiajs/vue3'],
    }
    ```

    Mirror the alias in `tsconfig.json` `paths` (`@tey/concierge` →
    `index.ts`, `@tey/concierge/*` → `*`).

2. **Tailwind v4.** In the host CSS entry, after `@import 'tailwindcss'`, add:

    ```css
    @import '../../vendor/tey/concierge/resources/css/concierge.css';
    ```

    This registers the package sources with `@source` and defines the
    `--concierge-*` variables. Override any variable in an unlayered `:root`
    rule to restyle Concierge. The pages use no host `@/UI` components.

3. **Plugin.** Install it once:

    ```ts
    app.use(createConcierge({ adapter: createInertiaAdapter() }));
    ```

4. **Wrapper pages.** The host owns layout, breadcrumbs and flash toasts. Pass
   the server props straight through, and use slots for chrome:

    ```vue
    <AppLayout><MembersPage v-bind="props"><template #breadcrumbs>…</template></MembersPage></AppLayout>
    ```

    Every page has the slots `header`, `breadcrumbs` and `aside`. `MembersPage`
    also has `empty`.

The peer dependencies the host must install are `vue ^3.5`, `tailwindcss ^4.1`
and, for the Inertia adapter, `@inertiajs/vue3 ^3`. Nothing else is imported.
`tests/Consumer/prove.sh` builds exactly this setup from a clean install.

## Local login button

`LocalLoginButton` renders a native `<form method="post">` to the URL the server
supplies. It is independent of `createConcierge` and of Inertia, so a host login
page can use it without installing either. It uses the same Vite alias, TypeScript
paths and `concierge.css` import as the pages (steps 1 and 2 above); nothing else
is needed.

Props:

- `offer: LocalLoginOffer | null | undefined` (`{ url, email }`). The component
  renders nothing when it is `null` or missing, so pass the server prop straight
  through. The server returns `null` in every environment except an enabled,
  configured `local` one with uncached process `APP_ENV=local`. Missing or
  nonlocal raw `APP_ENV` disables the offer even when configuration is cached.
- `csrfToken: string`, **required**. Your page supplies the session's CSRF token
  (for Inertia, share it from the server; for Blade, `csrf_token()`). It is sent
  as the hidden `_token` field, never in the URL, and never logged by the
  component.
- `label?: string`. Defaults to `Sign in as <email>`.
- `hint?: string`. Defaults to a line saying it is for local development only
  and skips the password and two-factor.

```vue
<script setup lang="ts">
import { LocalLoginButton, type LocalLoginOffer } from '@tey/concierge';

defineProps<{ localLogin: LocalLoginOffer | null; csrfToken: string }>();
</script>

<template>
    <LocalLoginButton :offer="localLogin" :csrf-token="csrfToken" />
</template>
```

Server side, pass `'localLogin' => app(\Tey\Concierge\LocalAuth\LocalLogin::class)->offer()`
as a page prop. The form contains the token and nothing else: the server signs in
as the account in its own configuration and ignores anything submitted.

While the request is in flight the button is disabled and shows a spinner, and a
second submit is ignored. The browser then follows the server's redirect;
validation or refusal messages (such as a soft-deleted account) come back in the
session error bag under `local_login`, and displaying them is the host's choice.

## Transport rules

- The UI never builds routes. It navigates and submits only to URLs from the
  props, and a `null` link hides the control. Endpoint authorization is still
  mandatory, because hidden UI is not security.
- Verbs:
    - POST: invite, suspend, reinstate, transfer ownership, resend, accept, logout
    - PUT: role changes
    - DELETE: remove, revoke
- The members search visits `metadata.refreshUrl` with the query `search` and
  `status`. Empty values are omitted.
- Validation errors arrive through the adapter as `{ errors }`.
    - Errors keyed `email`, `roles` or `roles.N` render on their fields.
    - Any other key (for example a policy denial) renders as a form-level alert.
- While a request is pending, repeated submits are ignored. Retry resends the
  identical request, so the server must be idempotent.
- Ownership transfer requires typing the recipient's name. The server is
  expected to redirect through password confirmation.

## Development

- `npm run types:generate` regenerates `types.ts` from `src/Data/UI` through
  the dev-only workbench provider. The Pest drift test fails when the
  committed file is stale.
- `npm run types:check` runs vue-tsc.
- `npm run test:browser` runs Playwright against the Vite harness on port 8152.
  It covers desktop and phone, and writes screenshots to `build/screenshots`.
- `npm run consumer:proof` runs the clean host install, type-check and build.
