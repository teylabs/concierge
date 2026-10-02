# Changelog

All notable changes to `concierge` will be documented in this file.

## Concierge 0.2.0 - 2026-10-02

Concierge now offers optional one-click local login, including idempotent account creation with a randomized password and an optional native Vue login button. Existing accounts are preserved. Local login is guarded by environment and configuration on every request, including cached routes.

Membership can be disabled for hosts that only need local authentication. Follow the package guide for configuration, Vite aliases, CSRF props and migration ordering.

Validation: PHP matrix, PostgreSQL 18, PHPStan and formatting passed on 59449bf. PostgreSQL full suite passed 104 tests (1087 assertions) and concurrency passed 4 tests. UI checks passed on 90f49c4; the subsequent PHP-only transaction fix did not trigger that workflow. Real-host cookbook adoption is the next integration check.

This release remains in the existing private repository.
