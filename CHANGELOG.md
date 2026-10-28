# Changelog

All notable changes to `concierge` will be documented in this file.

## [0.9.0] - 2026-10-28

### Added

- **Scoped invitations:** invite someone to act on one record with no other access in the workspace. An invitation takes an optional `scope` (a morph type and id, part of its fingerprint). Accepting it creates a guest-only membership plus a `concierge_membership_scopes` row (invited_by, accepted_at, revoked_at). A full member given a scope stays a full member and gains the scope (#138, closes issue 133).
- `ScopedAccess` contract with a membership-backed implementation, a `concierge.scoped:{param}[,members]` middleware, and facade helpers, for routes, policies and MCP tools to share one access check.
- `RevokeScope`: immediate. It kills a pending link, and removes the guest membership when it was the last scope; the account survives. `ScopeAccepted` and `ScopeRevoked` events.
- Guest exclusions by default: scoped guests are left out of member lists, role pickers, role changes, ownership transfer and `concierge.member` (a guest-only membership gets 403).

### Changed

- Accepting a scoped invitation whose record was deleted refuses with `scope_missing`, writes nothing and leaves the invitation pending. A swapped record is refused as `GrantsChanged` through the fingerprint.

### Upgrading

- Run the new migration (`update_concierge_invitations_with_scope`). App mode refuses a scope.

### Known limitations

- No invite UI, `concierge:invite` option or TypeScript types for scopes yet; those follow.

## [0.8.4] - 2026-10-28

### Added

- `WorkspaceContext::enter($workspace, $refresh)` and `leave()`, an explicit enter/leave alongside the scoped `run()`. Entries nest, and `leave()` with nothing entered throws `LogicException`. `run()` is now enter, try, finally leave, with unchanged behaviour. This lets a tenant switch task (spatie/laravel-multitenancy) hold the workspace context across a job or command (#136).

### Upgrading

- An app with its own `WorkspaceContext` implementation must add `enter()` and `leave()`.

## [0.8.3] - 2026-10-28

### Fixed

- An invited sign-up through Fortify's registration is created with its email verified when a remembered pending invitation (member or tenant) matches the address, so the invitee gets one email, not a second verification email.
- Accepting an invitation as a signed-in, unverified account whose email is the invited address verifies it inside the accept transaction (member, app-wide and tenant invitations) and reports it Ready. A different address is still Unverified.
- A successful password reset through the emailed signed link verifies the account, since it proves control of the mailbox. This covers the first owner created by `concierge:owners`. See README, Security (#134).

## [0.8.2] - 2026-10-25

### Changed

- Allows `tey/mod` 0.11, which adds removing and moving generated files on update and fixes discovery stopping production boots without dev dependencies (#130).

## [0.8.1] - 2026-10-21

### Added

- **Join as the signed-in account.** A new `concierge.invitations.match_email` setting (default `true`, today's behaviour). When it's `false`, someone signed in with a different, verified email can accept an invitation as themselves. Every other check still applies: expiry, revoked, the workspace accepting members, the inviter still authorised, grants unchanged, and already a member. The invitation records who accepted it and whether they used a different email (#127).
- The accept page offers "Join as {email}" before Switch account, with "This invitation was sent to {email}." Member rows in `MembersIndex` carry `acceptedWithOtherEmail` and `invitedEmail`, so a host can show who accepted.
- `docs/scenarios.md` adds the join-as cases and updates the accept decision tree.

### Upgrade notes

- Publish and run the new migration (`update_concierge_invitations_with_acceptor`). It adds one boolean column, defaulting to false.
- Nothing else changes unless you set `concierge.invitations.match_email` to `false`. To offer Join as, pass `otherEmail: $inspection->otherEmail`, `signedInAs` and the `switchAccount` link on the ready state.

## [0.8.0] - 2026-10-21

### Added

- **Switch account on invitations.** Someone signed in as a different email who opens an invitation sees who it is for, with Switch account and Cancel. Switching keeps a signed, short-lived invitation token through sign-out and sign-in (or registration) and returns them to accept it. A signed-out person with an existing account signs in and comes straight back to accept.
- **No-workspace landing.** A member who is removed, leaves or is suspended is moved to their next active workspace, or to none, and sent to `workspaces.no_workspace_url` instead of an error.
- `CopyInvitationLink` issues a new invitation link without sending an email, and the previous link stops working. `MembersIndex` offers it as a `copy_link` row action to people who may resend, with the confirmation "The previous link will stop working."
- Invitations take an optional personal `message` (up to 500 characters), set by `InviteMember` and `ResendInvitation`. It's shown on the invitation, on member-list rows and in the invitation email.
- Suspend and reinstate row actions ask for confirmation.
- `docs/scenarios.md`: every membership and invitation scenario with the test that proves it, plus diagrams of accepting an invitation, the membership lifecycle and where a member lands after signing in.

### Fixed

- Searching members and invitations ignores case on every database. It was case-sensitive on PostgreSQL.

### Upgrade notes

- Publish and run the new migration (`update_concierge_invitations_with_message`).
- Member-only workspace routes now return 404 to someone who was never a member (it was 403). A past member is redirected to `workspaces.no_workspace_url` instead.
- The `accept.wrong_account` label has new wording. `accept.wrong_account_as`, `accept.switchAccount` and `accept.cancel` are new labels.
- `MembersIndex` rows include a `copy_link` action when the host maps `invitation.copyLink`.

## [0.7.6] - 2026-10-20

### Fixed

- Allow `tey/mod` `^0.8`, `^0.9` and `^0.10` again. v0.7.5 narrowed the constraint back to `^0.6.1 || ^0.7` because the widening shipped in v0.7.4 had not reached `main`, so apps on `tey/mod` 0.8 to 0.10 could not install it (#125).

### Changed

- Bump the development dependency `vite` to `^8.3.4` (#124).

### Upgrade notes

- None. v0.7.6 is v0.7.5 with the `tey/mod` constraint of v0.7.4. Apps on v0.7.5 can update directly, and apps on v0.7.4 get everything in v0.7.5 (`LeaveWorkspace`, `MembersIndex`).

## [0.7.5] - 2026-10-20

### Added

- `LeaveWorkspace::run($user, $workspace)` lets a member end their own membership of one workspace. It takes the same locks as the other membership actions, clears the member's roles and permissions in that workspace only, and dispatches `MembershipRemoved` with the member as the actor. The owner is refused with `owner_must_transfer` until ownership is transferred (#122).
  
- `MembersIndex` builds the whole members page for a workspace, so an app or a UI kit renders it and carries no membership logic of its own (#123):
  
  - Members and Invitations tabs (`?tab`), each with its label, URL and a server-side count that follows the active search.
  - Sort, search, role and status filters and pagination through spatie/laravel-query-builder. An unknown sort, filter or tab is a 400, and `per_page` is capped at 100.
  - Each row carries only the actions the grant rules let the viewer take, decided by rank. Destructive actions carry their confirmation words, and nothing is shown disabled.
  - `table` gives the active tab's rows in the shape a generic data table takes, with a `first-use` or `no-results` empty state decided on the server.
  
- `MembersPage.vue` shows the tabs on Concierge's own primitives, through a new `ui/Tabs.vue` (#123).
  

### Changed

- Require `spatie/laravel-query-builder` `^7.3`.

### Upgrade notes

- No migration and no config key.
- TypeScript hosts that build `MemberRow`, `InvitationRow` or `MembersPageProps` by hand (fixtures, mocks) now need `actions: []`, and `tab`, `tabs` and `table` on the page props. Props built by PHP are unaffected.
- `LeaveWorkspace` and `MembersIndex` are workspace mode only. In app-wide mode they throw `UnsupportedConfiguration`.

## v0.7.4 - 2026-10-19

### Added

- `concierge:owners` creates the owners listed in `CONCIERGE_OWNERS` and sends each new one a set-password link. It is safe to run on every deploy, and takes `--workspace`, `--dry-run` and `--json` (#94).

### Changed

- Allow `tey/mod` `^0.10` alongside `^0.6.1`, `^0.7`, `^0.8` and `^0.9`.


---

**Full Changelog**: https://github.com/teylabs/concierge/compare/v0.7.3...v0.7.4

## v0.7.3 - 2026-10-19

### Changed

- Allow `tey/mod` `^0.9` alongside `^0.6.1`, `^0.7` and `^0.8`, so apps on mod 0.9 can install concierge.


---

**Full Changelog**: https://github.com/teylabs/concierge/compare/v0.7.2...v0.7.3

## v0.7.2 - 2026-10-19

### Changed

- Allow `tey/mod` `^0.8` alongside `^0.6.1` and `^0.7`, so apps on mod 0.8 can install concierge.


---

**Full Changelog**: https://github.com/teylabs/concierge/compare/v0.7.1...v0.7.2

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
