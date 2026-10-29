---
name: concierge-development
description: "Work with tey/concierge in a Laravel app: set it up with concierge:install, invite people and grant roles through its actions and concierge:* commands, protect routes, listen to its events, and choose app-wide or workspace mode. Use when the application requires tey/concierge and you touch users, roles, invitations, memberships, owners or workspaces. Not for apps without tey/concierge."
license: MIT
metadata:
  author: Jasper Tey / Tey Labs
---

# Concierge development

Concierge is glue over Laravel, spatie/laravel-permission and tey/mod. It owns invitations, memberships, ownership and role grants, and decides who may grant what. Use what it ships; don't rebuild it in app code.

## Rules

- Write through the actions in `Tey\Concierge\Actions`, never with queries on concierge's tables (`concierge_*`) or Spatie's (`roles`, `model_has_roles`). Each action takes the acting user first, checks the grant and management rules, locks what it changes and dispatches events.
- Authorize every request on the server. The actions refuse with an `AuthorizationException`; let it become a 403. A `null` link in page props hides a control, but your endpoint must still refuse.
- Read state through `Tey\Concierge\Facades\Concierge` (`membership()`, `ownerOf()`, `isOwner()`, `roleNames()`, `context()->current()`), not by querying.
- Mode and models are read while the provider registers. Change them in `config/concierge.php`, never at runtime or from `.env`.
- Every `concierge:*` command runs without prompts under `--no-interaction` and takes `--json`. Commands that write take `--dry-run`: preview with `--dry-run --json`, read the outcome, then run it.

## Setting up

Run `php artisan concierge:install`. It detects the starter kit, sign-in, user model, any team model and Spatie's setup, asks only what it can't detect, and reports one line per step (`done`, `already`, `would`, `skipped`, `manual`, `blocked`, `info`). Run it again after a `manual` step; a second run changes nothing that is in place.

- `concierge:install --dry-run --json` shows `{detected, choices, steps, set_up}` without changing anything.
- `concierge:install --mode=app` (the default) or `--mode=workspaces`; `concierge:install --workspace-model=App\\Models\\Team` adopts an existing model as workspaces.
- `concierge:install --local-login` turns on one-click local login when the app has no sign-in.
- `concierge:install --no-migrate` writes config without migrating or creating roles.
- It never edits models. When it reports `user-model: manual`, add `Tey\Concierge\Contracts\Member` to the user model's `implements` list and `HasRoles, InteractsWithMemberships` to its `use` line. A workspace model needs `Tey\Concierge\Contracts\Workspace` and `ActsAsWorkspace`. Both need incrementing integer keys.
- Until `config/concierge.php` exists, concierge registers nothing except `concierge:install`; `php artisan about` says whether it is set up.
- `concierge:sync-roles` creates the roles in `concierge.roles`; `concierge:sync-roles --dry-run --json` previews them.
- `concierge:owners` creates the owners listed in `CONCIERGE_OWNERS` and is safe on every deploy; in workspace mode pass `concierge:owners --workspace=<key>`. Preview with `concierge:owners --dry-run --json`.

## Inviting and granting roles

Workspace mode:

- `InviteMember::run($actor, $workspace, $email, new GrantSet(['admin']))` invites; `ResendInvitation`, `RevokeInvitation` and `CopyInvitationLink` manage a pending invitation; `InspectInvitation` and `AcceptInvitation` take the token from the link.
- `ChangeMemberRoles`, `SuspendMembership`, `ReinstateMembership`, `RemoveMembership` and `TransferOwnership` change a membership; `LeaveWorkspace` is the member leaving.
- `EstablishWorkspace::run($workspace, $owner)` makes the first owner of a new workspace.
- A scoped invitation (`InviteMember` with a record) reaches one record; `RevokeScope` removes it.

App-wide mode:

- `InviteUser`, `ChangeUserRoles`, `RemoveUser`, `UpdateUser`, `DeactivateUser`, `RestoreUser` and `ForcePasswordReset`; `EstablishOwner::run($owner)` makes the first owner.
- From the command line: `concierge:invite <email> --role=admin --as=<actor email>`, `concierge:roles <email> --role=member --as=<actor email>`, `concierge:deactivate <email> --as=<actor email> --reason="…"` and `concierge:restore <email> --as=<actor email>`. Each takes `--dry-run` and `--json`.

Tenant invitations (a platform owner invites someone to their own new workspace): `InviteTenant`, `ResendTenantInvitation`, `RevokeTenantInvitation`, `InspectTenantInvitation` and `AcceptTenantInvitation`.

`SyncRoleDefinitions` is what `concierge:sync-roles` runs.

With concierge's routes off, invitations are emailed only when the app sets `Concierge::invitationUrlUsing(fn ($invitation, string $token) => url("/invitations/{$token}"))`.

## Protecting routes

- `concierge.member` allows only an active member of the current workspace: 404 with no current workspace, 403 for anyone else.
- `concierge.active` refuses deactivated accounts; `concierge.scoped` allows a scoped guest only on their records.
- The current workspace comes from the bound `WorkspaceContext`: the current Spatie team on concierge's engine, or `{current_team}` / `current_team_id` on the kit engine.

## Routes, controllers and policies

Apps set up by `concierge:install` get concierge's own routes (`'routes' => ['enabled' => true]`), named `concierge.*`: the members list, invite, member page, role changes, suspend, reinstate, remove, transfer ownership, resend and revoke, and the invitation page with accept and switch account. Prefer them over writing your own. Apps set up before 0.10 have no `routes` key and keep them off. App-wide mode gets only the invitation routes, and the kit engine none.

- List them with `php artisan route:list --name=concierge`. Change paths with `routes.prefix`, wrap them with `routes.middleware` and `routes.invitation_middleware`, and name the Inertia components with `routes.pages`.
- Turn them off with `'routes' => ['enabled' => false]` and write your own controllers that call the actions.
- Replace one controller by binding your subclass in a service provider's `register()`: `$this->app->bind(\Tey\Concierge\Http\Controllers\MembersController::class, \App\Http\Controllers\MembersController::class)`.
- Narrow who may do what by defining the Gate abilities in `Tey\Concierge\Http\Abilities` yourself (`VIEW_MEMBERS`, `INVITE_MEMBERS`, `MANAGE_MEMBER`, `TRANSFER_OWNERSHIP`, `MANAGE_INVITATION`); concierge defines only those you haven't. An ability can't widen access, because the actions check the grant rules again; bind your own `GrantPolicy` for that.
- Render pages another way by binding `Tey\Concierge\Contracts\PageResponder`. Build props for your own controllers with `MembersIndex`, `MemberPage`, `InviteMemberPage` and `AcceptInvitationPage` in `Tey\Concierge\Support`.
- With the routes on, invitation emails link to `concierge.invitations.show` unless the app sets `Concierge::invitationUrlUsing()`.

## Pages

`concierge:pages` writes the Inertia wrapper pages into the app: `concierge:pages Admin` picks the folder, `concierge:pages --in=<module>` a mod layout group, `concierge:pages --skip-existing` keeps your copies and `concierge:pages --force` overwrites them. Preview with `concierge:pages --dry-run --json`. Build members-page props with `Tey\Concierge\Support\MembersIndex`.

## Events

Listen to the events in `Tey\Concierge\Events` rather than polling tables. They are dispatched after the transaction commits and carry ids and plain values: `MemberInvited`, `InvitationAccepted`, `InvitationResent`, `InvitationRevoked`, `InvitationLinkCopied`, `MemberRolesChanged`, `MembershipSuspended`, `MembershipReinstated`, `MembershipRemoved`, `OwnershipTransferred`, `OwnerEstablished`, `WorkspaceEstablished`, `ScopeAccepted`, `ScopeRevoked`, `UserInvited`, `UserRolesChanged`, `UserRemoved`, `UserUpdated`, `UserDeactivated`, `UserRestored`, `PasswordResetForced`, `TenantInvited`, `TenantInvitationAccepted`, `TenantInvitationResent` and `TenantInvitationRevoked`.

## Moving to workspaces

- An app with existing teams adopts them. A starter kit's teams use the kit engine (`'workspaces' => ['engine' => 'kit']`, Spatie teams off), which reads `team_members` and never writes it. Any other model with a pivot stays on concierge's engine with Spatie teams on.
- Copy an existing pivot's members in once with `concierge:import-members`. Preview first: `concierge:import-members --pivot=organisation_user --role-column=role --dry-run --json`. Options: `concierge:import-members --model=` (defaults to `concierge.models.workspace`), `--foreign-key=`, `--user-key=`, `--owner-role=`, `--owner-column=`, `--map=member:presenter` (repeatable) and `--default-role=`. It never changes the pivot, and running it again finds nothing to do.
- Moving an app-wide install to workspaces isn't automated yet; don't switch `mode` on a live schema by hand.
