# Changelog

All notable changes to `concierge` will be documented in this file.

## Concierge 0.3.0 - 2026-10-03

Concierge can now invite a person to a platform rather than into an existing workspace. A platform owner invites an email; accepting provisions that person's own workspace, which they own. Actions: `InviteTenant`, `ResendTenantInvitation`, `RevokeTenantInvitation`, `InspectTenantInvitation`, `AcceptTenantInvitation`.

The feature is opt-in (`features.tenant_invitations`) and adds nothing until enabled: no table, limiter or Gate definition. The host supplies the workspace factory, the link, the routes (tenant-less) and applies the named limiters. Platform ownership is a Gate ability backed by a configured email list. Multitenancy hosts must list `DeliverTenantInvitation` under `not_tenant_aware_jobs`. See the README.

## Concierge 0.2.0 - 2026-10-02

Concierge now offers optional one-click local login, including idempotent account creation with a randomized password and an optional native Vue login button. Existing accounts are preserved. Local login is guarded by environment and configuration on every request, including cached routes.

Membership can be disabled for hosts that only need local authentication. Follow the package guide for configuration, Vite aliases, CSRF props and migration ordering.

Validation: PHP matrix, PostgreSQL 18, PHPStan and formatting passed on 59449bf. PostgreSQL full suite passed 104 tests (1087 assertions) and concurrency passed 4 tests. UI checks passed on 90f49c4; the subsequent PHP-only transaction fix did not trigger that workflow. Real-host cookbook adoption is the next integration check.

This release remains in the existing private repository.
