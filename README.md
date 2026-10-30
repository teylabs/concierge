<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://concierge.teylabs.com/logo-dark.svg">
    <img alt="" src="https://concierge.teylabs.com/logo-light.svg" height="36" align="top">
  </picture>
  Concierge: Front of House for Laravel
</h1>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tey/concierge.svg?style=flat-square)](https://packagist.org/packages/tey/concierge)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/concierge/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/concierge/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/concierge/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/concierge/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tey/concierge.svg?style=flat-square)](https://packagist.org/packages/tey/concierge)

A standard suite of essentials for every Laravel app you build.

Concierge adds a standard suite of essentials and plumbing beyond Laravel's starter kits, so every app you build has a consistent foundation between `laravel new` and the product you're building.

Turn on local login, and your login page offers a one-click sign-in for local development:

```php
// config/concierge.php
'local_login' => [
    'enabled' => true,
    'account' => ['email' => 'dev@example.test', 'name' => 'Local developer'],
],
```

```php
// your login page's props
'localLogin' => app(\Tey\Concierge\LocalAuth\LocalLogin::class)->offer(),
```

With `LocalLoginButton` on that page, it shows **Sign in as dev@example.test**. One click creates the account if needed and signs you in. Outside the `local` environment the offer is `null`, the button renders nothing and the route answers 404.

> [!NOTE]
> Concierge is pre-1.0. Minor releases may change the API until 1.0.

- [Membership](https://concierge.teylabs.com/features/membership): invitations, memberships, ownership and role grants scoped to a workspace
- [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations): a platform owner invites an email, and accepting gives that person their own new workspace
- [Local login](https://concierge.teylabs.com/features/local-login): one-click sign-in as a configured account, in the `local` environment only
- [Vue pages](https://concierge.teylabs.com/features/vue-pages): optional Vue 3 pages for members and invitations, and the local-login button

The full documentation is at [concierge.teylabs.com](https://concierge.teylabs.com). What is planned on the way to 1.0 is in [ROADMAP.md](ROADMAP.md).

For coding agents: [llms.txt](https://concierge.teylabs.com/llms.txt) indexes every docs page, and [llms-full.txt](https://concierge.teylabs.com/llms-full.txt) is the whole documentation as one Markdown file.

## Installation

Concierge requires PHP 8.4 or 8.5 and Laravel 13.

```bash
composer require tey/concierge
```

Composer also installs [tey/mod](https://github.com/teylabs/mod), which concierge uses to write files into your app. Your app needs no setup for it and no `config/mod.php`: it gains the `mod:*` Artisan commands, and `php artisan optimize` caches mod's discovery.

Then set it up:

```bash
php artisan concierge:install
```

`concierge:install` detects your starter kit (Vue, React, Svelte, Livewire or none, and WorkOS AuthKit), your sign-in, your user model and any team or organisation model. It asks only what it can't detect: whether to use a team model it found as workspaces, app-wide or workspace mode (app-wide by default), and whether to turn on local login when the app has no sign-in. Every question has a flag, and `--no-interaction` takes the defaults. It then publishes and configures Spatie permission, writes `config/concierge.php` with concierge's [routes](#routes) turned on, migrates and creates the roles, printing one line per step. It never edits your models: if one is missing a contract, it prints the exact lines to add and stops, and you run it again. Running it again changes nothing that is already set up.

Until `config/concierge.php` exists, concierge registers nothing: no migrations, routes, middleware, listeners or commands other than `concierge:install`, so `php artisan migrate` creates no concierge tables. `php artisan about` shows whether it is set up. Set `'setup' => false` in the config to keep concierge unregistered anyway.

| Feature | Switch | Default |
| --- | --- | --- |
| [Membership](https://concierge.teylabs.com/features/membership) | `features.membership` | `true` |
| [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations) | `features.tenant_invitations` | `false` |
| [Local login](https://concierge.teylabs.com/features/local-login) | `local_login.enabled` | `false` |

Until your app has its own `config/concierge.php`, concierge registers nothing: no migrations, routes, middleware, listeners or commands, so `php artisan migrate` creates no concierge tables. `php artisan about` shows whether it is set up. Set `'setup' => false` in it to keep concierge unregistered anyway.

The switches are read while the package provider registers, so set them in `config/concierge.php`, not at runtime. See [Installation](https://concierge.teylabs.com/guide/installation) for the dependencies Composer brings in.

## Quick Start

After `concierge:install`, make someone the owner of a workspace:

```php
use App\Models\User;
use App\Models\Workspace;
use Tey\Concierge\Actions\EstablishWorkspace;

$owner = User::where('email', 'owner@example.com')->firstOrFail();
$workspace = Workspace::create(['name' => 'Acme']);

EstablishWorkspace::run($workspace, $owner);
```

`$owner` now holds the `owner` role in that workspace and can invite others. [Membership](https://concierge.teylabs.com/features/membership) has the contract methods and the rest of the setup.

An app that only wants local login sets `'features' => ['membership' => false]` in `config/concierge.php`; then `migrate` needs no workspace or permission setup.

**App-wide mode** is for the app with one organisation: invitations and roles apply to the whole app, there is no workspace model or table, and Spatie teams stay off. It is what `concierge:install` sets up by default; make the first owner with `EstablishOwner::run($owner)`. The actions are `InviteUser`, `ChangeUserRoles` and `RemoveUser`; `AcceptInvitation`, `ResendInvitation`, `RevokeInvitation` and the `concierge.member` middleware work in both modes. Owner is the top role and several owners are allowed; granting it is how ownership passes on, and the last owner can never be removed or demoted. Your app's own "delete account" keeps working: deleting a user model removes their concierge row first, and the last owner is refused with a validation message on the `password` field. The Users pages and the upgrade to workspaces follow in 0.8 ([#19](https://github.com/teylabs/concierge/issues/19)).

`ResendInvitation::run($actor, $invitation)` and `RevokeInvitation::run($actor, $invitation)` use the `AccountPolicy` for app-wide invitations. Resending requires authority to grant every invitation role, rotates the token, renews expiry and honours the resend cooldown; revoking requires management authority and is idempotent. Accepted invitations cannot be resent or revoked. An elapsed invitation still marked pending can be resent; one already marked expired cannot. The actions dispatch `InvitationResent` and `InvitationRevoked` with a null `workspaceId` in app-wide mode.

## Production

An invite-only app needs an owner before anyone can invite others. Set `CONCIERGE_OWNERS` to a comma-separated list of emails, read into `concierge.owners` with whitespace trimmed and blanks dropped:

```dotenv
CONCIERGE_OWNERS=owner@example.com,second@example.com
CONCIERGE_REGISTRATION=closed
```

Run the command after migrations on **every deploy**. In app-wide mode (Spatie teams off), several owners are allowed:

```bash
set -e
php artisan migrate --force
php artisan concierge:owners --no-interaction
php artisan optimize
```

In workspace mode (Spatie teams on), configure **one owner** and replace the owners line with `php artisan concierge:owners --workspace=1 --no-interaction`, using the key of an existing workspace. Your app creates the workspace; the command never does. A missing or unknown `--workspace`, several configured owners for one workspace, or an existing different owner exits non-zero without changing ownership. Starter kit teams are read-only and cannot be established by this command.

The command creates missing role definitions and looks up emails case-insensitively. For a new user it uses the email as the initial name, hashes a random password nobody knows, grants ownership and sends a set-password link through Laravel's default password broker. Configure that broker's user provider, mail delivery, `APP_URL` and your app's password-reset route before deploying. Completing the set-password link verifies the owner's email (see [Security](#security)). Existing users keep their name, email, password, verification state and other role grants. Removing an email from the setting never removes a user or their ownership. `EstablishOwner` and `EstablishWorkspace` also preserve existing role grants when establishing ownership.

**Only a user created by this invocation is emailed.** Later deploys never resend the link, even if it expired or the owner has not chosen a password yet. Use your app's forgot-password flow to request another link. A broker refusal rolls back that owner's creation and role changes and exits non-zero; earlier owners successfully processed in the same invocation remain.

The command never prompts. `--dry-run` previews without writing any rows or sending links. `--json` returns an array with exactly `email`, `outcome` and boolean `linkSent` per email. Outcomes are `created`, `role_added` or `already_owner`; dry-run uses `would_create`, `would_add_role` or `already_owner`, always with `linkSent: false`. Errors return an `error` object and a non-zero exit code. An empty list (with a valid `--workspace` in workspace mode) or already-established owners exits 0. `concierge.owners` and `tenant_invitations.platform_owners` are separate settings: owning an app or workspace does not authorize inviting tenants.

## Coding Agents

Concierge ships [Laravel Boost](https://laravel.com/docs/boost) guidance: `php artisan boost:install` picks up a short guideline and the `concierge-development` skill. With Boost installed, its MCP server also gains two read-only tools:

- `concierge-status`: whether concierge is set up, the mode and engine, the models and their key types, the configured roles, the owners and the `concierge:install` steps not yet in place. Owner emails are listed only in the `local` environment.
- `concierge-plan`: the `concierge:install --dry-run --json` plan.

The tools never write. Inviting, granting roles and creating owners stay with the `concierge:*` commands, so a person reviews every write.

## Command reference

Run these as `php artisan concierge:<command>`. Apart from `concierge:install`, they are registered once concierge is set up and `concierge.features.membership` is enabled. Every command runs without prompts under Laravel's `--no-interaction` (`-n`), and only `concierge:install` ever prompts. Missing input or a refused operation exits non-zero and names the flag, configuration setting or command that fixes it. `--json` emits one JSON value without progress output; `--dry-run` makes no writes or deliveries. Laravel's standard options, including `--help`, `--quiet` and `--no-ansi`, also apply.

### `concierge:install`

Sets concierge up; see [Installation](#installation). It is registered before setup, and it is the one command that asks questions, each paired with a flag. Every step reports `done`, `already`, `would` (dry run), `skipped`, `manual` (you must do it; the detail says what) or `blocked` (an earlier manual step stops it), and the command exits non-zero when a manual step stops it before migrating. Creating the first owner and setting the local-login account stay yours to do. On an app that is already set up it reads the mode and models from `config/concierge.php` and changes nothing it finds in place.

| Option | Behaviour |
| --- | --- |
| `--mode=` | `app` or `workspaces`. Default `app`, or `workspaces` with `--workspace-model`. |
| `--workspace-model=` | The model to use as workspaces. A starter kit's `Team` with a `team_members` pivot uses the kit engine, which reads the kit's teams and leaves Spatie teams off. |
| `--local-login` | Turn on local login when the app has no sign-in; you then set `local_login.account.email`. |
| `--no-migrate` | Write config, but don't migrate or create roles. |
| `--dry-run` | Show the plan and change nothing. |
| `--json` | `{detected, choices, steps, set_up}`; each step is `{step, status, detail}`. Errors: `{error, detected}`. |

### `concierge:sync-roles`

Creates missing global Spatie roles from `concierge.roles`, under `concierge.guard` (or the default auth guard). It never removes roles or changes assignments. Works in app-wide mode, workspace mode and the kit engine.

| Option | Behaviour |
| --- | --- |
| `--dry-run` | Preview missing roles without writing or clearing the permission cache. |
| `--json` | Array of `{name, guard, outcome}`. Outcomes: `created`, `already_exists`; dry-run: `would_create`, `already_exists`. Errors: `{error}`. |

### `concierge:owners`

Establishes the emails in `concierge.owners`. See [Production](#production) for broker setup and deploy behaviour.

| Option | Behaviour |
| --- | --- |
| `--workspace=<key>` | Existing workspace key; required in workspace mode, unused in app-wide mode. |
| `--dry-run` | Preview without writing rows or sending password links. |
| `--json` | Array of `{email, outcome, linkSent}`; outcomes and errors are described above. |

### `concierge:invite`

Invites an email into the app with roles, as an existing user. App-wide mode only. The invitation is delivered as it is from the Users page and the roles are granted on acceptance; every rule `InviteUser` applies holds unchanged (rank, not yourself, the last owner, the host `AccountGuard`).

| Option | Behaviour |
| --- | --- |
| `--role=*` | Repeatable; at least one required. A configured role (`concierge.roles`), granted on acceptance. |
| `--as=<email>` | Required; an existing user the invitation runs as. |
| `--dry-run` | Every check the action makes — authorization, the roster and pending-invitation rules — without writing, mailing or dispatching events, so the preview matches the real run. |
| `--json` | `{email, outcome, invitationId, roles, expiresAt}`; outcomes: `invited`, `would_invite`. Errors: `{error, code}`. |

`invitationId` is the invitation's id and `expiresAt` its expiry; the token and accept link never appear in the output. Refusal `code`s: `missing_actor`, `unknown_actor`, `missing_roles`, `unknown_role`, `unsupported_configuration`, `database`, the first validation key (`email`, `roles`), and the action's own (`not_member`, `not_manager`, `not_grantable`, plus the account guard's).

### `concierge:import-members`

Copies an existing pivot into Concierge's own workspace engine; app-wide mode and the kit engine refuse it. It never changes the source pivot. Ownership and existing memberships are kept; each workspace's writes are transactional, so a later failure leaves earlier workspaces imported.

| Option | Behaviour / default |
| --- | --- |
| `--model=<class>` | `concierge.models.workspace`; must match that setting. |
| `--pivot=<table>` | Laravel's pivot convention, e.g. `organisation_user`. |
| `--foreign-key=<column>` | Workspace key in the pivot; `<model>_id`. |
| `--user-key=<column>` | User key in the pivot; `user_id`. |
| `--role-column=<column>` | Optional source role column. |
| `--owner-role=<value>` | Source value marking the owner; `concierge.owner_role`. |
| `--owner-column=<column>` | Fallback owner key on the workspace; `owner_id`, ignored when absent. |
| `--map=<from>:<to>` | Repeatable source-to-Concierge role mapping. |
| `--default-role=<role>` | Role for unmapped members, or all members without a role column. |
| `--dry-run` | Plan the import without writing. |
| `--json` | `{dry_run, model, pivot, workspaces, summary, nothing_to_do}`; errors: `{error, fix}`. |

Each JSON workspace has `id`, `name`, `owner` and `members`. The owner has `user_id`, `email`, `source` and `action` (`establish` or `keep`), with an optional `note` when promoting an existing member. A member has `user_id`, `email`, `source_role`, `role` and `action`: `import` includes `joined_at`; `skip` includes `reason`. `summary` counts `workspaces`, `owners`, `members` and `skipped`. Dry-run reports the same planned actions with `dry_run: true`.

### `concierge:roles {email}`

Changes a user's app-wide roles through `ChangeUserRoles`, running as the `--as` user: the same rules — not yourself, not the last owner, not someone of equal or higher rank, a role the actor may grant, and the host `AccountGuard`'s veto. App-wide mode only.

| Option | Behaviour |
| --- | --- |
| `--role=<name>` | A role the user ends up with; repeat for several. At least one is required. |
| `--as=<email>` | The existing user the action runs as; required. Every rule the action applies holds unchanged. |
| `--dry-run` | Resolve both users and run the action's authorization check without writing or dispatching events. |
| `--json` | `{user, outcome, roles}`; refusals: `{error, code}`. |

`user` is the target's email, `roles` lists the resulting role names. Outcomes: `changed`, `unchanged`; dry-run: `would_change`, `unchanged`. Refusal `code`s: `missing_actor`, `unknown_actor`, `unknown_user`, `missing_roles`, `unknown_role`, `unsupported_configuration`, `database`, and the action's own (`self`, `rank`, `not_grantable`, `not_manager`, `not_member`, `last_owner`, plus the account guard's).

### `concierge:pages [name]`

Writes the four Inertia wrapper pages using mod. `name` is the folder under the app's pages directory, defaulting to `Concierge`; on a modules layout use `Billing:Concierge` or `--in=Billing`.

| Option | Behaviour |
| --- | --- |
| `--in=<group>` | Layout group or module to place the pages in. |
| `--force` | Overwrite existing pages. Takes precedence over `--skip-existing`. |
| `--skip-existing` | Keep existing pages and write the rest. |
| `--dry-run` | Preview without writing. Refused plans also exit non-zero. |
| `--json` | Mod's plan plus `dry_run`, `written` and `skipped` path arrays. |

The JSON keys are `command`, `group`, `name`, `files`, `inserts`, `warnings`, `would_write`, `dry_run`, `written` and `skipped`. `files` describes each planned page (`alias`, `type`, `path`, `identity`, `group`, `existing`, `exists`); `warnings` carries mod's diagnostic messages. Dry-run leaves `written` empty; `files` shows what would be written and `skipped` what would be kept. A refused plan adds `error` and writes nothing. A failure during generation returns `{error}`; it may have written earlier files. Without either collision flag, existing pages are refused rather than prompting.

### `concierge:deactivate <email>`

Deactivates a user as the `--as` user: the same `DeactivateUser` action the Users page calls, with the same rules — not yourself, not the last owner, not someone of equal or higher rank, and the host `AccountGuard`'s veto. App-wide mode only.

| Option | Behaviour |
| --- | --- |
| `--as=<email>` | The existing user the action runs as; required. Every rule the action applies holds unchanged. |
| `--reason=<text>` | Why the user is deactivated; stored on the account, truncated to 500 characters. |
| `--dry-run` | Resolve both users and run the action's authorization check without writing, mailing or dispatching events. |
| `--json` | `{user, outcome, sessionsEnded}`; refusals: `{error, code}`. |

`user` carries `id` and `email`. Outcomes: `deactivated`; dry-run: `would_deactivate`. `sessionsEnded` is what the action ended on the `database` session driver, and `null` on other drivers and on a dry run. Refusal `code`s: `missing_actor`, `unknown_actor`, `unknown_user`, `unsupported_configuration`, `database`, and the action's own (`already_deactivated`, `self`, `rank`, `not_manager`, `not_member`, `last_owner`, plus the account guard's).

### `concierge:restore <email>`

Restores a deactivated user as the `--as` user: the same `RestoreUser` action the Users page calls, with the same rules as deactivating — not yourself, not someone of equal or higher rank, and the host `AccountGuard`'s veto. The user's roles come back as they left them, and no invitation is sent. App-wide mode only.

| Option | Behaviour |
| --- | --- |
| `--as=<email>` | The existing user the action runs as; required. Every rule the action applies holds unchanged. |
| `--dry-run` | Resolve both users and run the action's authorization check without writing or dispatching events. |
| `--json` | `{user, outcome}`; refusals: `{error, code}`. |

`user` carries `id` and `email`. Outcomes: `restored`; dry-run: `would_restore`. Refusal `code`s: `missing_actor`, `unknown_actor`, `unknown_user`, `unsupported_configuration`, `database`, and the action's own (`not_deactivated`, `self`, `rank`, `not_manager`, `not_member`, `last_owner`, plus the account guard's).

## Usage

### Membership

Every operation is an action class in `Tey\Concierge\Actions`, called with `::run()`: invite, resend, revoke and accept invitations; change roles; suspend, reinstate and remove members; leave a workspace (`LeaveWorkspace::run($user, $workspace)`: a member ends their own membership, and the owner must transfer ownership first); transfer ownership; force a password reset. Actions check the grant rules in `config/concierge.php` and dispatch events. Concierge's [routes](#routes) call them for you after `concierge:install`; your own routes call them the same way, protected with the `concierge.member` middleware. See [Membership](https://concierge.teylabs.com/features/membership).

`concierge.member` returns 404 without a workspace and for a workspace the user never belonged to. Once a user is removed, leaves or is suspended, it repoints `CurrentWorkspace` (the session's current workspace) to their oldest other active membership, or to none, and redirects to `workspaces.no_workspace_url` (default `/`); JSON requests get a 403.

`InviteMember::run($actor, $workspace, $email, $grants, message: 'Welcome')` and `ResendInvitation::run($actor, $invitation, message: ...)` take an optional message of up to 500 characters; it is on the invitation, its `InvitationRow` and the delivered notification. `CopyInvitationLink::run($actor, $invitation)` rotates the token for a link you hand over yourself: the previous link stops working and nothing is emailed. `MembersIndex` offers it as the `copy_link` row action (route name `invitation.copyLink`) to anyone who may resend.

When the wrong account opens an invitation, the page names both emails and offers Switch account and Cancel (`switchAccount` and `cancel` links, `signedInAs` prop). Point Switch account at a POST route that returns `InvitationHandoff::switchAccount($request, $token, $returnTo, route('login'))`: it signs out and keeps the invitation in an encrypted cookie for 30 minutes, and the next sign-in or registration returns to `$returnTo` once. For a signed-out visitor, call `InvitationHandoff::start($token, $returnTo)` before sending them to sign in. The handoff also stands in for the register link in `invitation` and `domains` registration modes. Clear it with `InvitationHandoff::forget()` once accepted.

`DeactivateUser::run($actor, $user, reason: 'Left the company')` takes a user's access away and keeps their history; `RestoreUser::run($actor, $user)` gives it back. The state lives on the user's `concierge_accounts` row, not on the users table, so your own queries are untouched: filter on `status` when you want to hide deactivated people. The same rules as removal apply through the `AccountPolicy` and `AccountGuard`: not yourself, not the last owner, not someone of equal or higher rank. Roles and, in workspace mode, memberships stay as they are, so restoring brings back exactly what the user had, with no new invitation. A listener on Laravel's `Validated` event refuses a deactivated user with "This account is deactivated." on the `email` key once their credentials check out, which covers Fortify, the starter kits and `Auth::attempt`. Add the `concierge.active` middleware after your authentication middleware (for example `['auth:sanctum', 'concierge.active']`) to sign out a deactivated user on their next request; token requests get a 403 and the tokens are kept, so they work again after a restore. Deactivating also deletes the user's sessions when they live in the `database` driver and cycles the remember-me token; with other drivers the middleware ends the session. Workspace mode has no app-wide roles, so bind your own `AccountPolicy` to say who may deactivate. Listen to `UserDeactivated` and `UserRestored`.

`ForcePasswordReset::run($actor, $membership, resetTwoFactor: true)` locks an account down when it may be compromised: the password becomes one nobody knows, the remember-me token is cycled, two-factor is cleared when asked, and the person receives a reset link through your password broker. The same manager and rank rules apply as for suspending, and never to yourself. With the `database` session driver the person's sessions are deleted; with any other driver, add Laravel's `AuthenticateSession` middleware so their sessions end when the password hash changes. API tokens are untouched; listen to `PasswordResetForced`. In app-wide mode pass the user instead of a membership, `ForcePasswordReset::run($actor, $user)`: the rules are those of `DeactivateUser`, a deactivated account is refused, and `PasswordResetForced` carries a null `workspaceId` and `membershipId` (so does the `membership` on the result).

### Routes

`concierge:install` writes `'routes' => ['enabled' => true]` into `config/concierge.php`, and concierge then serves its own pages and endpoints, named `concierge.*`. A fresh app can invite, accept, change roles, suspend, reinstate, remove and transfer ownership with no app code. In workspace mode on concierge's own engine:

| Method | Path | Name |
| --- | --- | --- |
| GET | `members` | `concierge.members.index` |
| GET, POST | `members/invite` | `concierge.members.create`, `concierge.members.store` |
| GET | `members/{membership}` | `concierge.members.show` |
| PUT | `members/{membership}/roles` | `concierge.members.roles` |
| POST | `members/{membership}/suspend`, `/reinstate` | `concierge.members.suspend`, `concierge.members.reinstate` |
| DELETE | `members/{membership}` | `concierge.members.destroy` |
| POST | `members/{membership}/transfer-ownership` | `concierge.members.transfer-ownership` |
| POST or PUT | `members/invitations/{invitation}/resend` | `concierge.invitations.resend` |
| DELETE | `members/invitations/{invitation}` | `concierge.invitations.destroy` |
| GET | `invitations/{token}` | `concierge.invitations.show` |
| POST | `invitations/{token}/accept`, `/switch-account` | `concierge.invitations.accept`, `concierge.invitations.switch-account` |

App-wide mode gets only the three `invitations/{token}` routes for now; the Users pages follow in a later release. The kit engine gets none, since concierge doesn't write a starter kit's teams yet ([#57](https://github.com/teylabs/concierge/issues/57)).

- **Workspace.** The member routes take no workspace parameter. They use the bound `WorkspaceContext`: if your middleware already entered a workspace, that one; otherwise the session's `CurrentWorkspace`, which falls back to the user's oldest active membership. Anything else in the URL is looked up inside that workspace, so another workspace's id is a 404. `concierge.member` runs on every member route.
- **Config.** `routes.prefix` goes in front of every path (`'team'` gives `/team/members`). `routes.middleware` (default `['web', 'auth']`) wraps the member routes and `routes.invitation_middleware` (default `['web']`) the invitation page, which a signed-out visitor opens. `routes.home` is where Continue goes, and `routes.pages` names the Inertia component for each page; the defaults are the wrappers `concierge:pages` writes.
- **Pages.** With Inertia installed, pages render with `Inertia::render()`; without it they return the props as JSON. Bind `Tey\Concierge\Contracts\PageResponder` to render another way. Concierge's PHP does not require Inertia.
- **Invitation emails.** With the routes on, an invitation emails a link to `concierge.invitations.show` unless you call `Concierge::invitationUrlUsing()`, which still wins. With them off, nothing changes: nothing is emailed until you set a URL. A signed-out visitor is offered your `login` and `register` routes and comes back to the invitation afterwards.
- **Ownership transfer** goes through Laravel's `password.confirm` when your app has that route, and `TransferOwnership` still refuses until the password was confirmed recently.
- **Security.** Every endpoint checks a Gate ability first, and the action then checks the grant rules again. A hidden button is never the only check.

An app whose `config/concierge.php` has no `routes` key, which is every app set up before this release, keeps the routes off. Each piece can be replaced on its own.

Turn the routes off and keep your own:

```php
// config/concierge.php
'routes' => ['enabled' => false],
```

Replace a controller by binding your subclass; the routes resolve controllers from the container:

```php
// AppServiceProvider::register()
$this->app->bind(\Tey\Concierge\Http\Controllers\MembersController::class, \App\Http\Controllers\MembersController::class);
```

Override a policy by defining the Gate ability yourself; concierge only defines the ones you haven't:

```php
// AppServiceProvider::boot()
Gate::define(\Tey\Concierge\Http\Abilities::MANAGE_MEMBER, fn (User $user, Membership $membership) => $user->is_admin);
```

The abilities are `VIEW_MEMBERS` and `INVITE_MEMBERS` (given the workspace), `MANAGE_MEMBER` (roles, suspend, reinstate and remove) and `TRANSFER_OWNERSHIP` (given the membership), and `MANAGE_INVITATION` (resend and revoke). An ability can narrow what the routes allow but never widen it, because the actions check the grant rules too; bind your own `GrantPolicy` to change those. `MemberPage`, `InviteMemberPage` and `AcceptInvitationPage` in `Tey\Concierge\Support` build the props for your own controllers, as `MembersIndex` does for the list.

### Scoped Invitations

Invite someone to one record in a workspace (a project or a document) and nothing else. Workspace mode only: app-wide mode refuses a scope.

Add a scoped role, last in `roles`, and give it no permissions. It is never offered or granted workspace-wide, so `grantableRoles()` and your role pickers leave it out:

```php
'guest' => ['label' => 'Guest', 'grantable_by' => ['owner', 'admin'], 'scoped' => true],
```

Then pass the record as `scope` (or a `Tey\Concierge\Support\Scope` with a type and id):

```php
$issued = InviteMember::run($actor, $workspace, 'guest@example.test', new GrantSet(['guest']), scope: $document);
```

The record is stored as its morph class (the morph map alias when you have one) and key, and is part of the grants fingerprint, so an invitation to another record is a separate pending invitation. A scoped invitation grants scoped roles only, and anyone who manages the workspace and may grant the role (`grantable_by`) may send it. Resend, copy link and expiry work as for any invitation, and the record travels with it.

If the record has since been deleted, or its type no longer resolves, accepting is refused with an `AuthorizationException` coded `scope_missing`; nothing is written and the invitation stays pending, so revoke it. A record swapped on the invitation fails the fingerprint and is refused as grants changed.

Accepting it adds a scope record (`concierge_membership_scopes`) to the invitee's membership and dispatches `ScopeAccepted`:

- Someone with no active membership gets a **guest** membership holding only the scoped role. A second scoped invitation adds another record to the same guest membership.
- An active full member keeps their membership and roles, and gains the scope. They are not a guest.
- A guest who accepts an ordinary invitation becomes a full member with its roles; their scopes stay.

Check access with the bound `Tey\Concierge\Contracts\ScopedAccess`. `Concierge::allowsScoped($user, $record)` is true only when the user holds an active scope for exactly that record, through an active membership of the record's workspace: the workspace you pass as the third argument, else the record's `workspace_id`, else the current workspace context. It says nothing about roles, so full members are not "scoped". Combine the two in your policy:

```php
public function edit(User $user, Document $document): bool
{
    return $user->can('documents.edit') || Concierge::allowsScoped($user, $document);
}
```

On routes, `concierge.scoped:document` lets through only users scoped to the bound `{document}` (403 otherwise); `concierge.scoped:document,members` also lets through active full members of the current workspace, for a route both share, with their roles still yours to check. `concierge.member` refuses scoped guests with a 403, so a guest never reaches workspace-wide routes. Concierge ships no MCP server: from an MCP tool, a job or a command, call `Concierge::allowsScoped()` (or resolve `ScopedAccess`) the same way. Rebind `ScopedAccess` to change the rule.

`Concierge::isScopedGuest($membership)`, `Concierge::scopes($membership)`, `Concierge::scopeFor($membership, $record)` and `Concierge::scopedTo($membership, $record)` read the rest. `RevokeScope::run($actor, Concierge::scopeFor($membership, $document))` takes the record away at once: pending invitations to that record for the member's email are revoked too, and when it was a guest's last scope their guest membership is removed (the user account stays). It dispatches `ScopeRevoked`. Removing a member, or a member leaving, revokes their scopes as well.

Scoped guests are left out of the members index and `MembershipSource::members()`; ask for them with `MembersIndex::includingScopedGuests()` and `ConciergeMembershipSource::includingScopedGuests()`. Their rows never offer a role change or an ownership transfer, `ChangeMemberRoles` refuses them, and ownership never passes to a guest.

### Tenant Invitations

For an invite-only app: a platform owner invites an email, and accepting creates that person's own workspace, which they own. It is off by default and ships no routes; your app provides the workspace factory, the link and the register-or-sign-in pages. See [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations).

### Existing Teams

An app that already has a team or organisation model can use it as the workspace. Add `implements Workspace` and the `ActsAsWorkspace` trait (the name comes from a `name` column, and the workspace accepts members unless it is soft-deleted).

- **A starter kit's teams** (`laravel new --teams`): set `'workspaces' => ['engine' => 'kit']` and point `models.workspace` at the kit's `Team`. Concierge reads each team's members and their `team_members.role`, resolves the current team from `{current_team}` or `users.current_team_id`, and never writes the kit's tables. Protect team routes with `concierge.member`: it reads kit membership, returns 403 for a non-member and 404 without a workspace. Spatie teams stays off. On the user model, `HasRoles` and the kit's `HasTeams` both define `teams()`, so keep the kit's: `use HasRoles, HasTeams { HasTeams::teams insteadof HasRoles; }`. Concierge's own write actions refuse a kit team for now ([#57](https://github.com/teylabs/concierge/issues/57)).
- **Any other model with a pivot** stays on concierge's own engine (add `HasMembers` too, with Spatie teams on). Copy its members in once with `php artisan concierge:import-members --pivot=organisation_user --role-column=role --dry-run --json`. The command picks an owner, in this order: the role column's owner, the model's `owner_id`, then the earliest member. It never changes the pivot, and running it again finds nothing to do.

### Local Login

One click signs in as the account in your configuration, in the `local` environment only, checked on every request. It works without membership. See [Local login](https://concierge.teylabs.com/features/local-login) for the exact rules, including cached configuration.

With membership enabled, `local_login.first_owner` defaults to `true`: when the app has no owner, the signed-in account becomes its owner through `EstablishOwner`. In workspace mode it becomes the owner of the oldest workspace through `EstablishWorkspace`, only if that workspace has no owner. No workspace means no grant, and sign-in still succeeds; local login never creates a workspace. An existing owner means no roles or memberships change, even for a different configured account. Set `'first_owner' => false` in `local_login` to grant nothing. Plain-account guards and read-only starter-kit teams are unchanged.

The grant uses the same fail-closed environment checks as sign-in: both Laravel and the uncached process `APP_ENV` must be exactly `local`, including with cached configuration. It never grants ownership outside `local`, and does not mark the session as recently authenticated.

### Vue Pages

`MembersIndex` builds the whole members page in workspace mode: `app(MembersIndex::class)->urlsUsing($urls)->props($actor, $workspace, $request)` returns `MembersPageProps` with a Members and an Invitations tab (`?tab`), each tab's count (which follows the active search), and the active tab's rows as a server-driven table (`table`: rows, columns, sort, search, filters, pagination and an empty state decided on the server). The query is [spatie/laravel-query-builder](https://spatie.be/docs/laravel-query-builder): `?sort=name|joined` (`email|sent|expires` for invitations, with `-` for descending), `?filter[search]`, `?filter[role]`, `?filter[status]`, `?page` and `?per_page` (capped at 100); an unknown sort, filter or tab is a 400 and nothing is silently ignored. Every row carries only the actions the grant rules let the viewer take (`actions`, each with its URL, method and, for destructive ones, the words to confirm with), so an admin gets none on the owner and nothing is shown disabled. `$urls` is `fn (string $action, ?Model $subject): ?string`, naming where each action goes in your app (`index`, `invite`, `member.show`, `member.updateGrants`, `member.suspend`, `member.reinstate`, `member.remove`, `member.transferOwnership`, `invitation.resend`, `invitation.revoke`); an action with no URL is left out. To narrow who is listed (hiding a support role, say), pass `scopeMembersUsing(fn (Builder $memberships, Workspace $workspace) => …)`: it is given the memberships query, joined to the users table, and applies to the Members tab's rows and its count alike. App-wide mode lists users instead and refuses it.

Optional Vue 3 pages (`MembersPage`, `InviteMemberPage`, `MemberPage`, `UsersPage`, `InviteUserPage`, `UserPage`, `AcceptInvitationPage`) and `LocalLoginButton` ship in the package under `resources/js`. The Members pages are for workspaces (`MembersPage` shows the tabs when the page props carry them); the Users pages are for app-wide mode. The PHP code never depends on them. In a Vue starter kit app, `php artisan concierge:pages` writes the four wrapper pages into your own `resources/js/pages/Concierge`, ready to edit (`--dry-run` previews them; mod lists the same recipe as `mod:concierge/pages`). Concierge declares the `@tey/concierge` alias, its TypeScript paths, its Tailwind source and its stylesheet to [mod](https://mod.teylabs.com), so `php artisan mod:install inertia --dry-run` shows the wiring and `mod:install inertia` applies it to a starter kit app. In an app you wire by hand, add `@import '@tey/concierge/styles.css';` to your CSS entry. See [Vue pages](https://concierge.teylabs.com/features/vue-pages) for the Vite, Tailwind and plugin setup by hand.

#### TypeScript types

Type your own Members, Member, invite and accept pages against the generated page props that concierge's controllers (or yours) supply. Vue apps can import them from `@tey/concierge`; plain TypeScript or other frontends can use `@tey/concierge/types`, which imports no Vue components and needs no Vue tooling. Both entries export the same generated types, including the Users page props and row types. Mod's existing `@tey/concierge/*` TypeScript path covers `/types`; no extra wiring is needed. The `types.ts` contract will be frozen at 1.0 ([#36](https://github.com/teylabs/concierge/issues/36)).

```ts
import type { MembersPageProps } from '@tey/concierge';
```

Or, without Vue tooling:

```ts
import type {
    MembersPageProps,
    MemberPageProps,
    InviteMemberPageProps,
    AcceptInvitationPageProps,
} from '@tey/concierge/types';
```

#### Users Pages (App-Wide Mode)

`UsersPage` lists everyone with search, a role filter and a status filter (Active by default, Deactivated, or both), paginated; set `metadata.status` to `null` or `'active'` to hide deactivated users, `'deactivated'` for only them and `'all'` for everyone. `UserPage` edits a user's name, email and roles and links to removal, and `InviteUserPage` sends the app-wide invitation. Their props are `UsersPageProps`, `UserPageProps` and `InviteUserPageProps` in `types.ts`, and they never say "workspace". Concierge ships no routes for the Users pages yet (app-wide mode gets only the [invitation routes](#routes) for now), so your controllers build the props and supply every link; a `null` link hides its control. Wire them to `ChangeUserRoles`, `RemoveUser`, `InviteUser` and `UpdateUser`. Changing an email does not clear `email_verified_at` or send a verification email; that is your app's call. Removal calls `delete()` on the user model, so a model with `SoftDeletes` is soft deleted. `UserPage` and the rows of `UsersPage` offer Deactivate (with an optional reason, sent as `reason`) and Restore when `UserLinks` carries a `deactivate` or `restore` link; both POST, so point them at routes that call `DeactivateUser` and `RestoreUser`. They also offer Force password reset when `UserLinks` carries a `forcePasswordReset` link (hidden for yourself and for deactivated users): it POSTs to a route that calls `ForcePasswordReset`, and sends `resetTwoFactor: true` when the person ticked "Also reset two-factor authentication", a box shown only when the row's `twoFactorResettable` is true. Your controller decides which users to list: filter on the account `status` to match the metadata.

`UpdateUser::run($actor, $user, name: ..., email: ...)` edits another user's name and email under the same rules as removal; your own profile stays with your app's profile page. A `null` argument leaves that field alone. The name is required (255 characters at most) and the email must be valid and unique on the users table, ignoring case, or a `ValidationException` keyed `name` or `email` is thrown. It writes the model's `name` and `email` attributes, which `conciergeDisplayName()` and `conciergeEmail()` read, and dispatches `UserUpdated` with the old and new value of each changed field.

To add your own columns, name them and return their values for each user, then put the same values on each `UserRow`:

```php
// app/Providers/AppServiceProvider.php
use Tey\Concierge\Facades\Concierge;

Concierge::userColumns(
    ['plan' => 'Plan', 'seats' => 'Seats'],
    fn ($user) => ['plan' => $user->plan, 'seats' => $user->seats],
);

// In the controller: send the labels in the metadata and the values on each row.
new UsersMetadata(columns: Concierge::userColumnDefinitions(), /* ... */);
new UserRow(columns: Concierge::userColumnValues($user), /* ... */);
```

Columns show after Joined. Render a cell yourself with the `column` slot, which receives `column`, `user` and `value`:

```vue
<UsersPage v-bind="props">
    <template #column="{ column, value }">
        <PlanBadge v-if="column.key === 'plan'" :plan="value" />
        <template v-else>{{ value }}</template>
    </template>
</UsersPage>
```

`AcceptInvitationPage` says "Join {app name}" when the invitation has no workspace; pass the name in `metadata.appName`, or leave it out for generic "Join" wording.

Every person on these pages has an avatar: the app's photo when it has one, and otherwise their initials on a colour that stays the same for that person everywhere. Nothing is fetched from an outside service. Build a `UserSummary` with `UserSummary::for($user)` and tell concierge where the photo lives:

```php
// app/Providers/AppServiceProvider.php
use Tey\Concierge\Facades\Concierge;

Concierge::avatarUsing(fn ($user) => $user->profile_photo_url); // Jetstream
```

The `Avatar` component is exported for your own pages, and the eight `--concierge-avatar-*` colours are CSS variables you can override.

## Configuration

The published `config/concierge.php` lists every key with a comment on each: the models, the roles and who may grant them, invitation expiry, tenant invitations and local login. Each feature page explains the keys it uses.

Who may register is one setting, `CONCIERGE_REGISTRATION` (`open` by default, `domains`, `invitation` or `closed`), enforced in Fortify's registration with no app code. See [Registration](https://concierge.teylabs.com/features/registration).

## FAQ

### How Is This Different from spatie/laravel-permission, Starter-Kit Teams or an Admin Panel?

- **[spatie/laravel-permission](https://spatie.be/docs/laravel-permission)** is the roles and permissions engine, and concierge builds on it. Concierge adds what sits on top: invitations, ownership, rules for who may grant which role, suspension, and the events for each.
- **Laravel's starter kits** give an app sign-in, registration and, when generated with them, teams. Concierge picks up from there with membership administration as a package, so it updates with `composer update`. The [roadmap](https://concierge.teylabs.com/roadmap) shows how it plans to work with each kit's own teams.
- **An admin panel** gives you screens over your tables. Concierge gives you the flows themselves (invitation expiry, ownership transfer, grant rules), which an admin panel can call.

### What Does My App Still Provide?

Authentication, your layout and the wrapper pages (`concierge:pages` writes them). In workspace mode concierge's [routes](#routes) cover the members pages and invitations; in app-wide mode, the Users pages still need your routes and controllers. Each [feature page](https://concierge.teylabs.com/guide/introduction) lists exactly what it expects.

## Documentation

Everything else is at [concierge.teylabs.com](https://concierge.teylabs.com), with a compact index for coding agents at [llms.txt](https://concierge.teylabs.com/llms.txt).

## Testing

```bash
composer test
composer analyse
composer lint
npm run types:check
npm run consumer:proof
```

## Changelog

See [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Contributing

See [CONTRIBUTING](https://github.com/teylabs/.github/blob/main/CONTRIBUTING.md). Questions and ideas go to [Discussions](https://github.com/teylabs/concierge/discussions).

## Security

Concierge marks an account's email verified only when it has proof of the mailbox, and never for an unproven address:

- **Accepting an invitation** (member, app-wide or tenant) with the emailed token proves the invited address. An unverified account whose email is the invited one is verified inside the accept transaction, and `InspectInvitation` reports it as `Ready`. An unverified account with another address (`invitations.match_email` off) is still `Unverified`.
- **Registering through an invitation** with Fortify (the token on the form or remembered from the register link, for that exact email) creates the account verified. Fortify's `Registered` → `SendEmailVerificationNotification` then sends nothing, so an invited person gets one email: the invitation.
- **Resetting a password** through the emailed, signed link (Laravel's `PasswordReset` event) verifies an unverified account. This covers the first owner from `concierge:owners`, whose set-password link is a reset link, and anyone who resets before verifying.

Everything else about verification stays your app's: changing an email doesn't clear `email_verified_at` or send a verification email.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- Scaffolded from [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
- [Jasper Tey](https://github.com/jaspertey)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
