# Concierge Vue UI

Optional, reusable Vue 3 pages for Concierge membership administration, plus a
local-login button that works without them. The PHP core never depends on this
directory or on Inertia.

The exports, host integration (Vite, Tailwind, the plugin, wrapper pages), the
local-login button and the transport rules are documented on the
[Vue pages](https://concierge.teylabs.com/features/vue-pages) page of the docs
site.

## Development

- `npm run types:generate` regenerates `types.ts` from `src/Data/UI` through
  the dev-only workbench provider. The Pest drift test fails when the
  committed file is stale.
- `npm run types:check` runs vue-tsc.
- `npm run test:browser` runs Playwright against the Vite harness on port 8152.
  It covers desktop and phone, and writes screenshots to `build/screenshots`.
- `npm run consumer:proof` runs the clean host install, type-check and build
  (`tests/Consumer/prove.sh`).
