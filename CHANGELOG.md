# Changelog

All notable changes to `concierge` will be documented in this file.

## Concierge 0.7.0 - 2026-10-11

Concierge is now built on [mod](https://mod.teylabs.com): one command wires its pages into your app. This release also ships early, opt-in pieces of 0.8.

### New

- **Built on mod** (#17, #18).
  - Concierge requires `tey/mod` ^0.6.1. It declares its Inertia pages (the `concierge::` namespace) and the frontend wiring a host needs: the `@tey/concierge` alias, its TypeScript paths and its Tailwind source.
  - `php artisan mod:install inertia --dry-run` shows the edits, and `mod:install inertia` applies them. That replaces the hand-written Vite alias, `tsconfig.json` paths and Tailwind source. You still import `concierge.css` yourself, until #81.
  - It works whether concierge is installed from Packagist or symlinked from a Composer path repository.
  
- **`php artisan concierge:pages`** writes concierge's Inertia wrapper pages into your own `resources/js/pages/Concierge`, placed by your app's mod layout, for you to own and edit. `--dry-run` and `--json` preview them.
  - A React app is told which `.tsx` stubs to supply rather than getting Vue code.
  

### Early, completes in 0.8

These are opt-in. An app that leaves the new `mode` key unset behaves exactly as on 0.6.

- **App-wide mode** (#19).
  - `'mode' => 'app'` is for an app with one organisation: invitations and roles apply to the whole app, with no workspace model, and Spatie teams stay off.
  - The actions are `EstablishOwner`, `InviteUser`, `ChangeUserRoles` and `RemoveUser`. Owner is the top role, and the last owner can never be removed, demoted or deleted through your app's own "delete account".
  - App-wide invitations can't be resent or revoked yet (#79).
  
- **Starter kit teams** (#20). `'workspaces' => ['engine' => 'kit']` reads a starter kit's teams, members and roles through an adapter and never writes the kit's tables.
  - `php artisan concierge:import-members` copies an existing pivot into concierge's own engine.
  - Protect kit routes with the kit's own `EnsureTeamMembership` for now; `concierge.member` reads concierge's own memberships (#80).
  
- **The Users pages** (part of #43): `UsersPage`, `UserPage` and `InviteUserPage` for app-wide mode.
  - They come with search, role and status filters, plus `Concierge::userColumns()` for your own columns.
  - `UpdateUser` edits a user's name and email.
  - Concierge ships no routes yet (#28), so your controllers build the props and supply the links.
  
- **Deactivation** (#44).
  - `DeactivateUser` and `RestoreUser` take a user's access away and give it back, keeping their roles and history.
  - A deactivated user is refused at sign-in, through Laravel's `Validated` auth event.
  - The new `concierge.active` middleware signs out existing sessions and refuses API tokens with a 403 without deleting them.
  

### Upgrading

`composer update tey/concierge tey/mod`, then `php artisan migrate`. That adds the `concierge_accounts` table, and changes nothing else. If you published concierge's migrations into your app, copy the new `create_concierge_user_accounts_table` migration from the package's `database/migrations` alongside them.

- **Mode:** an app on workspaces should add `'mode' => 'workspaces'` to `config/concierge.php`. Until 1.0, concierge infers it from `models.workspace`, and `php artisan about` shows a deprecation line until you add the key.
- **Frontend:** run `php artisan mod:install inertia --dry-run` to see what mod would add. Hand-wired apps keep working.
- **Scaffold name:** mod lists the pages recipe as `mod:concierge/pages` (it was `mod:concierge-pages`). `php artisan concierge:pages` is unchanged.
- **Page props:** `AcceptInvitationMetadata` gains an optional `appName`, so existing code keeps working.

## Concierge 0.6.0 - 2026-10-11

Three essentials beyond membership, and two fewer dependencies.

### New

- **Registration gating** (#45). One setting decides who may create an account: `CONCIERGE_REGISTRATION` set to `open`, `domains`, `invitation` or `closed`, plus `CONCIERGE_REGISTRATION_DOMAINS` for `domains` mode.
  
  - In `invitation` and `domains` modes, someone holding a valid invitation can register with the email it was sent to. Expired, revoked, already accepted and forged invitations are refused with a message saying what to do. So are invitations for another email.
  - It fails closed: an unknown or empty mode counts as `closed`, and so does `domains` mode with no domains. `closed` turns the register page into a 404.
  - With Fortify it works with no app code. Otherwise, add the `Tey\Concierge\Rules\MayRegister` rule to the email field.
  
- **Admin-forced password reset** (part of #47).
  
  - `ForcePasswordReset::run($actor, $membership, resetTwoFactor: false)` locks an account down: it replaces the password with an unknown random hash, cycles the remember token, and ends the user's sessions when sessions are stored in the database. It then sends a reset link through the user's own reset notification.
  - It follows the same manager, rank, owner and recent-authentication rules as suspending a member. A new `AccountGuard` contract lets the app veto it for particular accounts.
  - It fires `PasswordResetForced`. The settings are under `forced_password_reset` in `config/concierge.php`.
  
- **Initials avatars** (#48).
  
  - `UserSummary` carries an `avatar`: a photo URL when the app has one, and otherwise initials and a stable palette colour. Nothing is fetched from an outside service.
  - Tell concierge where photos live with `Concierge::avatarUsing(fn ($user) => $user->profile_photo_url)`.
  - The Vue kit exports an `Avatar` component, and the members and member pages use it.
  

### Changed

- **Concierge no longer requires `lorisleiva/laravel-actions` or `spatie/laravel-data`** (#51).
  - Actions are plain classes. `EstablishWorkspace::run(...)` and every other documented `::run(...)` call still work.
  - Page props are plain `readonly` classes with `toArray()` and JSON serialization, and their output is unchanged.
  - The generated TypeScript contract, `types.ts`, is byte-identical apart from the new avatar fields.
  

### Upgrading

`composer update tey/concierge`. No migrations.

- **Registration:** the default mode is `open`, so an app that upgrades keeps the registration it had. Set `CONCIERGE_REGISTRATION=invitation` (or another mode) to restrict it.
- **Dependencies:** if your app uses laravel-actions or laravel-data itself and only had them because concierge required them, add them to your own `composer.json` before updating.
- **Page props:** page-prop classes are now `readonly`. Code that changed a prop object after building it, or extended one as a non-readonly class, needs to change. Building and passing them as documented is unaffected.
- **Avatars:** `UserSummary` takes an optional fourth argument, so existing constructor calls keep working. The TypeScript `UserSummary` gains `avatar`.

## Concierge 0.5.0 - 2026-10-11

Concierge is now public, on Packagist, and documented at https://concierge.teylabs.com.

### Install

```bash
composer require tey/concierge



```
Apps that added this GitHub repository to Composer can remove that `repositories` entry.

### Changes

- Local login is tested across the full environment matrix: caches, every source of `APP_ENV`, exact values, php-fpm, `php artisan serve` and long-lived workers. It is offered only when both Laravel's environment and the serving process's `APP_ENV` are exactly `local`. No behaviour change from 0.4.1. (#53, closes #9)
- New README and documentation site, including configuration and actions-and-events reference pages. (#59, closes #10, #11, #12, #14)
- `composer lint` runs Pint in test mode. (#56)
- Updated a development-only build dependency, source-map-js 1.2.2. (#54)

No changes to package behaviour or the public API.

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

Validation: PHP matrix, PostgreSQL 18, PHPStan and formatting passed on 59449bf. PostgreSQL full suite passed 104 tests (1087 assertions) and concurrency passed 4 tests. UI checks passed on 90f49c4; the subsequent PHP-only transaction fix did not trigger that workflow. Real-host adoption is the next integration check.
