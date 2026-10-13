# Changelog

All notable changes to `concierge` will be documented in this file.

## v0.7.1 - 2026-10-13

### Changed

- Allow `tey/mod` `^0.7` alongside `^0.6.1`, so apps on mod 0.7 can install concierge (#110).


---

**Full Changelog**: https://github.com/teylabs/concierge/compare/v0.7.0...v0.7.1

## [0.7.0] - 2026-10-11

Concierge is now built on [mod](https://mod.teylabs.com): one command wires its pages into your app. This release also brings early, opt-in parts of 0.8. An app that leaves the new `mode` key unset behaves exactly as on 0.6.

### Upgrading

- **A new `concierge_accounts` table arrives with `php artisan migrate`.** If you published concierge's migrations into your app, copy the new `create_concierge_user_accounts_table` migration from the package's `database/migrations` alongside them.
- **Workspace apps should declare their mode.** Add `'mode' => 'workspaces'` to `config/concierge.php`. Until 1.0, concierge infers it from `models.workspace`, and `php artisan about` shows a deprecation line until you add the key.

### Added

- Concierge declares its Inertia pages and frontend wiring to mod: the `@tey/concierge` alias, its TypeScript paths and its Tailwind source.
  - `php artisan mod:install inertia --dry-run` shows the edits, and `mod:install inertia` applies them. That replaces the hand-written alias, paths and source.
  - It works whether concierge is installed from Packagist or symlinked from a path repository.
  - You still import `concierge.css` yourself, until #81. (#17, #70, #76)
  
- `php artisan concierge:pages` writes concierge's Inertia wrapper pages into your own `resources/js/pages/Concierge`, placed by your app's mod layout. A React app is told which `.tsx` stubs to supply. (#62)
- App-wide mode, early and opt-in (`'mode' => 'app'`).
  - Invitations and roles apply to the whole app, with no workspace model, through `EstablishOwner`, `InviteUser`, `ChangeUserRoles` and `RemoveUser`.
  - The last owner can never be removed, demoted or deleted.
  - App-wide invitations can't be resent or revoked yet (#79).
  - It completes in 0.8. (#19, #65, #69)
  
- Starter kit teams, early and opt-in (`'workspaces' => ['engine' => 'kit']`).
  - Concierge reads a kit's teams, members and roles through an adapter and never writes the kit's tables.
  - `php artisan concierge:import-members` copies an existing pivot into concierge's own engine.
  - Protect kit routes with the kit's `EnsureTeamMembership` for now (#80). (#20, #68)
  
- The Users pages for app-wide mode, early: `UsersPage`, `UserPage` and `InviteUserPage`.
  - They have search, role and status filters, plus `Concierge::userColumns()` for your own columns.
  - `UpdateUser` edits a user's name and email.
  - Your controllers supply the props and links until concierge ships routes (#28). (#43, #75, #77)
  
- Deactivation, early.
  - `DeactivateUser` and `RestoreUser` take a user's access away and give it back, keeping their roles and history.
  - A deactivated user is refused at sign-in.
  - The `concierge.active` middleware signs out existing sessions and refuses API tokens without deleting them. (#44, #74)
  

### Changed

- Concierge requires `tey/mod` ^0.6.1. Mod lists the pages recipe as `mod:concierge/pages` (it was `mod:concierge-pages`); `php artisan concierge:pages` is unchanged. (#70, #76)
- `AcceptInvitationMetadata` gains an optional `appName`, so existing code keeps working (#75).

## [0.6.0] - 2026-10-11

Registration gating, an admin-forced password reset and initials avatars, and two fewer dependencies.

### Upgrading

- **Concierge no longer requires `lorisleiva/laravel-actions` or `spatie/laravel-data`.** If your app uses either one itself and only had it because concierge required it, add it to your own `composer.json` before updating.
- **Page props are `readonly` classes.** Code that changed a prop object after building it, or that extended one as a non-readonly class, needs to change. Building and passing them as documented is unaffected.

### Added

- Registration gating. `CONCIERGE_REGISTRATION` is `open` (the default), `domains`, `invitation` or `closed`, with `CONCIERGE_REGISTRATION_DOMAINS` for `domains` mode.
  - In `invitation` and `domains` modes, someone holding a valid invitation can register with the email it was sent to.
  - It fails closed: an unknown or empty mode counts as `closed`, and so does `domains` mode with no domains.
  - It works with Fortify with no app code. Otherwise, add the `MayRegister` rule to the email field. (#45)
  
- `ForcePasswordReset::run($actor, $membership, resetTwoFactor: false)`.
  - It replaces the password with an unknown one, cycles the remember token, and ends the user's database sessions. It then sends a reset link through the user's own reset notification.
  - The same rules as suspending a member apply, and a new `AccountGuard` contract can veto it.
  - It fires `PasswordResetForced`. (#47)
  
- Initials avatars. `UserSummary` carries an `avatar`: a photo URL when the app has one (`Concierge::avatarUsing(...)`), and otherwise initials and a stable colour, with no outside requests. The Vue kit exports an `Avatar` component. (#48)

### Changed

- Actions are plain classes, and every documented `::run(...)` call still works. Page props are plain `readonly` classes with unchanged output. The generated `types.ts` is byte-identical apart from the avatar fields. (#51)

## [0.5.0] - 2026-10-11

Concierge is public, installs with `composer require tey/concierge`, and is documented at https://concierge.teylabs.com. Apps that added this GitHub repository to Composer can remove that `repositories` entry.

### Added

- A new README and a documentation site, including configuration and actions-and-events reference pages (#59, #10, #11, #12, #14).

### Changed

- Local login is tested across the full environment matrix: caches, every source of `APP_ENV`, exact values, php-fpm, `php artisan serve` and long-lived workers. Its behaviour is unchanged from 0.4.1 (#53, #9).
- `composer lint` runs Pint in test mode (#56).
- A development-only build dependency is updated: source-map-js 1.2.2 (#54).

## [0.4.1] - 2026-10-04

### Security

- Local login fails closed when a cached configuration disagrees with the running process. A configuration cache built under `APP_ENV=local` and served by a production process could previously offer and accept passwordless local login. Local login now requires both Laravel's environment and the process's own `APP_ENV` to be exactly `local`, and a missing value fails closed. Route registration, the login offer and a request-time check all use the same rule. A cached local development boot must supply `APP_ENV=local` through the runtime environment (#6, #7).

## [0.4.0] - 2026-10-04

### Upgrading

- **Re-inviting an email that already has a tenant workspace is refused by default,** with "That email already has a workspace." Set `tenant_invitations.allow_reinvite_after_accept = true` to keep 0.3's behaviour, which gives them a second workspace.

### Changed

- Re-inviting an email that already has a tenant workspace is refused unless `allow_reinvite_after_accept` is on (#4).
- The README covers a `Gate::before` that returns true: it also grants `concierge.manage-tenants`, and the README gives an opt-out snippet (#4).

### Fixed

- Resending a tenant invitation keeps the original `inviter_id`. The resender is the event's `actorId` (#4).

## [0.3.0] - 2026-10-03

### Added

- Optional tenant invitations, off by default (`concierge.features.tenant_invitations`). A platform owner invites an email, and accepting gives the invitee their own new workspace, which they own. With the flag off, no table, rate limiter or Gate ability is registered.
  - The actions are `InviteTenant`, `ResendTenantInvitation` (which rotates the token), `RevokeTenantInvitation`, `InspectTenantInvitation` and `AcceptTenantInvitation`.
  - Accepting needs the invited, verified email. Your app provisions the workspace through `Concierge::provisionWorkspaceUsing()`, in the same transaction.
  - Platform owners come from the `concierge.tenant_invitations.platform_owners` email list or a Gate ability, and the check fails closed.
  - Delivery isn't tenant-aware: apps on spatie/laravel-multitenancy list `DeliverTenantInvitation` in `not_tenant_aware_jobs`.
  

## [0.2.0] - 2026-10-02

### Added

- Optional one-click local login: sign in as the account in your configuration in the `local` environment. The account is created once, with a randomized password, and existing accounts are kept. Every request checks the environment and configuration, including with cached routes.
- An optional Vue `LocalLoginButton`.
- Membership can be switched off for apps that only need local login.
