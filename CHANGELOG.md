# Changelog

All notable changes to `concierge` will be documented in this file.

## Concierge 0.4.1 - 2026-10-04

Security fix for local login (#6, #7).

- **Local login fails closed when a cached configuration disagrees with the process.** A configuration cache built under `APP_ENV=local` and served by a production process could previously offer and accept passwordless local login. Local login now requires both Laravel's application environment and the uncached process `APP_ENV` to be exactly `local`; a missing value fails closed. Route registration, the login offer and a request-time check (before session and CSRF, so locally cached routes are covered) all use the same predicate.
- Laravel's application environment and configuration are left unchanged; only local login is affected. A cached local development boot must supply `APP_ENV=local` through the runtime environment.

## Concierge 0.4.0 - 2026-10-04

Follow-ups from the 0.3.0 review (#4).

- **Behaviour change:** `InviteTenant` now refuses an email whose tenant invitation was already accepted ("That email already has a workspace."), instead of letting a second workspace be provisioned. Set `tenant_invitations.allow_reinvite_after_accept` to `true` for the old behaviour.
- `ResendTenantInvitation` no longer overwrites `inviter_id`; the resender is `actorId` on `TenantInvitationResent`. The member `ResendInvitation` is unchanged on purpose: its `inviter_id` is the authority re-checked at acceptance, and a resend re-snapshots the grants under the resender.
- README: a host `Gate::before` returning true also grants `concierge.manage-tenants` (with an opt-out), and `InspectTenantInvitation` revealing account existence to a token holder is documented as accepted. Both are pinned by tests.

## Concierge 0.3.0 - 2026-10-03

Concierge can now invite a person to a platform rather than into an existing workspace. A platform owner invites an email; accepting provisions that person's own workspace, which they own. Actions: `InviteTenant`, `ResendTenantInvitation`, `RevokeTenantInvitation`, `InspectTenantInvitation`, `AcceptTenantInvitation`.

The feature is opt-in (`features.tenant_invitations`) and adds nothing until enabled: no table, limiter or Gate definition. The host supplies the workspace factory, the link, the routes (tenant-less) and applies the named limiters. Platform ownership is a Gate ability backed by a configured email list. Multitenancy hosts must list `DeliverTenantInvitation` under `not_tenant_aware_jobs`. See the README.

## Concierge 0.2.0 - 2026-10-02

Concierge now offers optional one-click local login, including idempotent account creation with a randomized password and an optional native Vue login button. Existing accounts are preserved. Local login is guarded by environment and configuration on every request, including cached routes.

Membership can be disabled for hosts that only need local authentication. Follow the package guide for configuration, Vite aliases, CSRF props and migration ordering.

Validation: PHP matrix, PostgreSQL 18, PHPStan and formatting passed on 59449bf. PostgreSQL full suite passed 104 tests (1087 assertions) and concurrency passed 4 tests. UI checks passed on 90f49c4; the subsequent PHP-only transaction fix did not trigger that workflow. Real-host cookbook adoption is the next integration check.

This release remains in the existing private repository.
