# Working on concierge

concierge (`tey/concierge`) is a Laravel package (PHP 8.4+, Laravel 13) for users, roles and workspaces. It builds on spatie/laravel-permission and tey/mod, with optional Inertia + Vue pages. Read `README.md`, `ROADMAP.md`, the docs at https://concierge.teylabs.com and the issue you're working on before changing code.

## Gates

Run these before opening or updating a pull request:

- `composer test`: the full Pest suite on SQLite.
- `composer analyse`: PHPStan.
- `composer lint`: Pint (`composer format` fixes style).

When you touch `resources/js`, `src/Data/UI` or the harness, also run:

- `vendor/bin/pest tests/Data/UI`, then `npm run types:generate` if the generated `types.ts` is out of date;
- `npm run types:check` and `npm run format:check`;
- `npm run test:browser` (Playwright, desktop and phone).

CI also runs the suite on PostgreSQL 18 and a clean consumer install (`npm run consumer:proof`). Every check must be green on the PR's final commit.

## Conventions

- Follow the surrounding code: naming, structure, comment density. Concierge is glue: it reuses Laravel, Spatie and mod rather than reinventing them.
- Every change gets tests that fail without it. Test both modes where it matters: app-wide (`tests/AppMode`) and workspaces, with Spatie teams on and off.
- Migrations in `database/migrations` are published into apps: never change one that has been released; add a new one.
- Don't edit `CHANGELOG.md`; it's written from the release notes. Put upgrade notes in the PR body.
- Update the README when a documented command, option or default changes.
- Prose uses Canadian spelling ("behaviour", "colour"; "-ize" is fine): plain, short sentences, no marketing.

## Git and pull requests

- Work on a branch from the latest `origin/main`; never commit to `main`.
- Commit as `Jasper Tey <jasper@teylabs.com>`, with imperative, sentence-case subjects (e.g. "Add app-wide user deactivation").
- **No attribution of any kind:** no `Co-authored-by`, no agent thread IDs, no "Generated with" lines, no links to agent sessions, in commits, PR titles or PR bodies.
- Open pull requests as **drafts**. Title them like a commit subject; the body says what changed, how it was verified, and `Closes #N`.
- Never merge, push to `main`, tag or release. Maintainers do that.
- This is a public repository: never include secrets, local absolute paths, private app names or internal notes.
