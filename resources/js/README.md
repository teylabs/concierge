# Concierge Vue UI

Optional, reusable Vue 3 pages for Concierge membership administration. The PHP
core never depends on this directory or on Inertia.

## Exports

`@tey/concierge` (this directory's `index.ts`):

- Pages: `MembersPage`, `InviteMemberPage`, `MemberPage`, `AcceptInvitationPage`.
  Each takes the matching generated `*PageProps` type as its props.
- `createConcierge({ adapter, labels?, locale? })`: a Vue plugin that every page requires.
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

The primitives in `ui/` are adapted from the cookbook UI kit
(`resources/js/UI`: Surface, Button, Badge/StatusBadge, FormGroup,
OptionsCheckbox, ConfirmationModal, Paginator, EmptyState). Each file notes
its provenance.
