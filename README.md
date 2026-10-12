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

## Installation

Concierge requires PHP 8.4 or 8.5 and Laravel 13.

```bash
composer require tey/concierge
```

Composer also installs [tey/mod](https://github.com/teylabs/mod), which concierge uses to write files into your app. Your app needs no setup for it and no `config/mod.php`: it gains the `mod:*` Artisan commands, and `php artisan optimize` caches mod's discovery.

The service provider is registered through package discovery. Publish the configuration to choose features and models:

```bash
php artisan vendor:publish --tag="concierge-config"
```

| Feature | Switch | Default |
| --- | --- | --- |
| [Membership](https://concierge.teylabs.com/features/membership) | `features.membership` | `true` |
| [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations) | `features.tenant_invitations` | `false` |
| [Local login](https://concierge.teylabs.com/features/local-login) | `local_login.enabled` | `false` |

The switches are read while the package provider registers, so set them in `config/concierge.php`, not at runtime. See [Installation](https://concierge.teylabs.com/guide/installation) for the dependencies Composer brings in.

## Quick Start

Membership is on by default, and its migration reads your user and workspace models, so set those up **before** you migrate:

1. Implement `Tey\Concierge\Contracts\Member` on your user model (with Spatie's `HasRoles`) and `Tey\Concierge\Contracts\Workspace` on your workspace model. Both tables need incrementing integer keys.
2. Publish the [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) config and migrations, and set `permission.teams` to `true`.
3. Set workspace mode and the two models in `config/concierge.php`:

    ```php
    'mode' => 'workspaces',

    'models' => [
        'user' => App\Models\User::class,
        'workspace' => App\Models\Workspace::class,
    ],
    ```

4. Migrate, then create the configured roles:

    ```bash
    php artisan migrate
    php artisan concierge:sync-roles
    ```

Then make someone the owner of a workspace:

```php
use App\Models\User;
use App\Models\Workspace;
use Tey\Concierge\Actions\EstablishWorkspace;

$owner = User::where('email', 'owner@example.com')->firstOrFail();
$workspace = Workspace::create(['name' => 'Acme']);

EstablishWorkspace::run($workspace, $owner);
```

`$owner` now holds the `owner` role in that workspace and can invite others. [Membership](https://concierge.teylabs.com/features/membership) has the contract methods and the rest of the setup.

An app that only wants local login sets `'features' => ['membership' => false]` first; then `migrate` needs no workspace or permission setup.

**App-wide mode** is for the app with one organisation: invitations and roles apply to the whole app, there is no workspace model or table, and Spatie teams stay off. Set `'mode' => 'app'`, leave `models.workspace` null, migrate and sync the roles as above, then make the first owner with `EstablishOwner::run($owner)`. The actions are `InviteUser`, `ChangeUserRoles` and `RemoveUser`; `AcceptInvitation`, `ResendInvitation`, `RevokeInvitation` and the `concierge.member` middleware work in both modes. Owner is the top role and several owners are allowed; granting it is how ownership passes on, and the last owner can never be removed or demoted. Your app's own "delete account" keeps working: deleting a user model removes their concierge row first, and the last owner is refused with a validation message on the `password` field. The Users pages and the upgrade to workspaces follow in 0.8 ([#19](https://github.com/teylabs/concierge/issues/19)).

`ResendInvitation::run($actor, $invitation)` and `RevokeInvitation::run($actor, $invitation)` use the `AccountPolicy` for app-wide invitations. Resending requires authority to grant every invitation role, rotates the token, renews expiry and honours the resend cooldown; revoking requires management authority and is idempotent. Accepted invitations cannot be resent or revoked. An elapsed invitation still marked pending can be resent; one already marked expired cannot. The actions dispatch `InvitationResent` and `InvitationRevoked` with a null `workspaceId` in app-wide mode.

## Usage

### Membership

Every operation is an action class in `Tey\Concierge\Actions`, called with `::run()`: invite, resend, revoke and accept invitations; change roles; suspend, reinstate and remove members; transfer ownership; force a password reset. Actions check the grant rules in `config/concierge.php` and dispatch events. Your app keeps its routes, controllers, policies and authentication, and protects routes with the `concierge.member` middleware. See [Membership](https://concierge.teylabs.com/features/membership).

`DeactivateUser::run($actor, $user, reason: 'Left the company')` takes a user's access away and keeps their history; `RestoreUser::run($actor, $user)` gives it back. The state lives on the user's `concierge_accounts` row, not on the users table, so your own queries are untouched: filter on `status` when you want to hide deactivated people. The same rules as removal apply through the `AccountPolicy` and `AccountGuard`: not yourself, not the last owner, not someone of equal or higher rank. Roles and, in workspace mode, memberships stay as they are, so restoring brings back exactly what the user had, with no new invitation. A listener on Laravel's `Validated` event refuses a deactivated user with "This account is deactivated." on the `email` key once their credentials check out, which covers Fortify, the starter kits and `Auth::attempt`. Add the `concierge.active` middleware after your authentication middleware (for example `['auth:sanctum', 'concierge.active']`) to sign out a deactivated user on their next request; token requests get a 403 and the tokens are kept, so they work again after a restore. Deactivating also deletes the user's sessions when they live in the `database` driver and cycles the remember-me token; with other drivers the middleware ends the session. Workspace mode has no app-wide roles, so bind your own `AccountPolicy` to say who may deactivate. Listen to `UserDeactivated` and `UserRestored`.

`ForcePasswordReset::run($actor, $membership, resetTwoFactor: true)` locks an account down when it may be compromised: the password becomes one nobody knows, the remember-me token is cycled, two-factor is cleared when asked, and the person receives a reset link through your password broker. The same manager and rank rules apply as for suspending, and never to yourself. With the `database` session driver the person's sessions are deleted; with any other driver, add Laravel's `AuthenticateSession` middleware so their sessions end when the password hash changes. API tokens are untouched; listen to `PasswordResetForced`.

### Tenant Invitations

For an invite-only app: a platform owner invites an email, and accepting creates that person's own workspace, which they own. It is off by default and ships no routes; your app provides the workspace factory, the link and the register-or-sign-in pages. See [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations).

### Existing Teams

An app that already has a team or organisation model can use it as the workspace. Add `implements Workspace` and the `ActsAsWorkspace` trait (the name comes from a `name` column, and the workspace accepts members unless it is soft-deleted).

- **A starter kit's teams** (`laravel new --teams`): set `'workspaces' => ['engine' => 'kit']` and point `models.workspace` at the kit's `Team`. Concierge reads each team's members and their `team_members.role`, resolves the current team from `{current_team}` or `users.current_team_id`, and never writes the kit's tables. Protect team routes with `concierge.member`: it reads kit membership, returns 403 for a non-member and 404 without a workspace. Spatie teams stays off. On the user model, `HasRoles` and the kit's `HasTeams` both define `teams()`, so keep the kit's: `use HasRoles, HasTeams { HasTeams::teams insteadof HasRoles; }`. Concierge's own write actions refuse a kit team for now ([#57](https://github.com/teylabs/concierge/issues/57)).
- **Any other model with a pivot** stays on concierge's own engine (add `HasMembers` too, with Spatie teams on). Copy its members in once with `php artisan concierge:import-members --pivot=organisation_user --role-column=role --dry-run --json`. The command picks an owner, in this order: the role column's owner, the model's `owner_id`, then the earliest member. It never changes the pivot, and running it again finds nothing to do.

### Local Login

One click signs in as the account in your configuration, in the `local` environment only, checked on every request. It works without membership. See [Local login](https://concierge.teylabs.com/features/local-login) for the exact rules, including cached configuration.

### Vue Pages

Optional Vue 3 pages (`MembersPage`, `InviteMemberPage`, `MemberPage`, `UsersPage`, `InviteUserPage`, `UserPage`, `AcceptInvitationPage`) and `LocalLoginButton` ship in the package under `resources/js`. The Members pages are for workspaces; the Users pages are for app-wide mode. The PHP code never depends on them. In a Vue starter kit app, `php artisan concierge:pages` writes the four wrapper pages into your own `resources/js/pages/Concierge`, ready to edit (`--dry-run` previews them; mod lists the same recipe as `mod:concierge/pages`). Concierge declares the `@tey/concierge` alias, its TypeScript paths and its Tailwind source to [mod](https://mod.teylabs.com), so `php artisan mod:install inertia --dry-run` shows the wiring and `mod:install inertia` applies it to a starter kit app. See [Vue pages](https://concierge.teylabs.com/features/vue-pages) for the Vite, Tailwind and plugin setup by hand.

#### TypeScript types

Type your own Members, Member, invite and accept pages against the generated page props your controllers supply. Vue apps can import them from `@tey/concierge`; plain TypeScript or other frontends can use `@tey/concierge/types`, which imports no Vue components and needs no Vue tooling. Both entries export the same generated types, including the Users page props and row types. Mod's existing `@tey/concierge/*` TypeScript path covers `/types`; no extra wiring is needed. The `types.ts` contract will be frozen at 1.0 ([#36](https://github.com/teylabs/concierge/issues/36)).

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

`UsersPage` lists everyone with search, a role filter and a status filter (Active by default, Deactivated, or both), paginated; set `metadata.status` to `null` or `'active'` to hide deactivated users, `'deactivated'` for only them and `'all'` for everyone. `UserPage` edits a user's name, email and roles and links to removal, and `InviteUserPage` sends the app-wide invitation. Their props are `UsersPageProps`, `UserPageProps` and `InviteUserPageProps` in `types.ts`, and they never say "workspace". Concierge ships no routes yet ([#28](https://github.com/teylabs/concierge/issues/28)), so your controllers build the props and supply every link; a `null` link hides its control. Wire them to `ChangeUserRoles`, `RemoveUser`, `InviteUser` and `UpdateUser`. Changing an email does not clear `email_verified_at` or send a verification email; that is your app's call. Removal calls `delete()` on the user model, so a model with `SoftDeletes` is soft deleted. `UserPage` and the rows of `UsersPage` offer Deactivate (with an optional reason, sent as `reason`) and Restore when `UserLinks` carries a `deactivate` or `restore` link; both POST, so point them at routes that call `DeactivateUser` and `RestoreUser`. Your controller decides which users to list: filter on the account `status` to match the metadata.

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

Routes, controllers and policies that call the actions; authentication; and your layout. Each [feature page](https://concierge.teylabs.com/guide/introduction) lists exactly what it expects.

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

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- Scaffolded from [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
- [Jasper Tey](https://github.com/jaspertey)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
