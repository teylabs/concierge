# Concierge

A standard suite of essentials for every Laravel app you build.

Concierge is a Laravel package. It currently has two independent features:

- **Membership**: invitations, memberships, ownership and role grants scoped to a workspace. On by default.
- **Local login**: one-click sign-in as a configured account in the `local` environment only. Off by default, and it works without the membership feature.

## Requirements

- PHP 8.4 or 8.5
- Laravel 13
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) 8.x
- [lorisleiva/laravel-actions](https://laravelactions.com) 2.x
- [spatie/laravel-data](https://spatie.be/docs/laravel-data) 4.x

Membership mode additionally requires:

- Spatie permission **teams enabled** (`permission.teams = true`). Concierge throws `UnsupportedConfiguration` otherwise.
- Incrementing integer keys on the user and workspace tables (the package migration uses `foreignId`). UUID keys are not supported.
- A workspace model, even for a single-organisation app: create exactly one and use it everywhere.

Local login needs only an Eloquent user model on the guard you sign in with.

## Installation

```bash
composer require tey/concierge
```

The service provider is registered through Laravel package discovery.

## Membership

### Order matters

The package registers its migration automatically, and that migration reads your user and workspace models and tables. Any `php artisan migrate` run after the package is installed will try to run it, so put everything it reads in place **first**:

1. Define your user and workspace models and their migrations. Both need incrementing integer keys, and the models must implement the Concierge contracts (see Configuration).
2. Publish the Spatie permission config and migrations, and enable teams (`permission.teams = true`).
3. Publish `config/concierge.php` and set `concierge.models.user` and `concierge.models.workspace`.
4. Only then run `php artisan migrate`, followed by `php artisan concierge:sync-roles` to create the configured roles.

If `migrate` runs before step 3, the Concierge migration fails with an `UnsupportedConfiguration` error naming the missing model setting.

### Configuration

```bash
php artisan vendor:publish --tag="concierge-config"
```

```php
// config/concierge.php
'models' => [
    'user' => App\Models\User::class,           // Eloquent, implements Tey\Concierge\Contracts\Member, uses HasRoles
    'workspace' => App\Models\Workspace::class, // Eloquent, implements Tey\Concierge\Contracts\Workspace
],
```

Values in the host application's `config/concierge.php` take precedence over the package defaults. The published file lists every key, including the roles, who may grant them, invitation expiry and the recent-authentication window.

### What the host owns

Concierge provides the domain actions, events, models and an optional Vue UI. The host application owns:

- HTTP controllers, routes and policies that call the actions
- authentication (login, Fortify, password setup and confirmation)
- the workspace context (the default resolves the current Spatie team) and bootstrapping the first workspace and owner
- the invitation URL (`Concierge::invitationUrlUsing()`)
- for tenant invitations, the workspace factory, link, routes and throttling (see below)

Hiding a control in the UI is not authorization: enforce every action on the server.

## Tenant invitations

For a SaaS host that is invite-only: a **platform owner** invites an email, and accepting gives that person their **own new workspace**, which they own. This is separate from membership invitations, which bring someone into an *existing* workspace (invitees can still use those inside their own workspace). There are no plans, quotas or billing.

It is off by default: no table, rate limiter or Gate definition exists until you opt in. Set it in `config/concierge.php` **before** publishing or running migrations:

```php
'features' => ['membership' => true, 'tenant_invitations' => true],

'tenant_invitations' => [
    'platform_owners' => ['owner@example.com'], // verified emails; or define the Gate ability yourself
],
```

Then publish the migration (`concierge-migrations`) and migrate. Like local login, the switch is read while the provider registers, so it cannot be set at runtime.

### Actions

| Action | Who | What |
| --- | --- | --- |
| `InviteTenant::run($actor, $email)` | platform owner | Creates a pending invitation (one per email) and queues delivery. Returns the invitation and the one-time token. Refuses an email whose invitation was already accepted ("That email already has a workspace.") unless `tenant_invitations.allow_reinvite_after_accept` is true, in which case accepting provisions an additional workspace. |
| `ResendTenantInvitation::run($actor, $invitation)` | platform owner | Rotates the token (old link dies), renews expiry, honours `invitations.resend_cooldown_seconds`. The original `inviter_id` is kept; the resender is `actorId` on `TenantInvitationResent`. |
| `RevokeTenantInvitation::run($actor, $invitation)` | platform owner | Revokes a pending invitation. Revoking twice is a no-op; an accepted one cannot be revoked. |
| `InspectTenantInvitation::run($user, $token)` | anyone | Read-only state for your register / sign-in page: `RegisterRequired`, `SignInRequired`, `Ready`, `WrongAccount`, `Unverified`, `Expired`, `Revoked`, `Accepted`, `AlreadyMember` or `Invalid`. The returned invitation holds the email to lock your register form to. `SignInRequired` versus `RegisterRequired` tells a holder of a valid, pending token whether an account exists for the invited address. That is accepted: only the invitee's own address is revealed, only for an acceptable token (invalid, expired, revoked and accepted tokens reveal nothing), and the inspect limiter bounds it. |
| `AcceptTenantInvitation::run($user, $token)` | the invitee | Provisions the workspace and makes `$user` its owner. Idempotent for the same user. |

Models and queries: `TenantInvitation` with `outstanding()`, `lapsed()`, `accepted()` and `revoked()` scopes, and `workspace` / `inviter` relations, which is enough to build an admin page (tenants, pending, accepted and revoked invitations). `concierge.models.tenant_invitation` swaps the model.

### Authorization

Platform ownership lives outside workspaces and permission teams. Every mutating action checks one Gate ability, `concierge.tenant_invitations.ability` (default `concierge.manage-tenants`):

1. Define it yourself (`Gate::define('concierge.manage-tenants', fn ($user) => ...)`) for full control.
2. Otherwise Concierge defines it from `tenant_invitations.platform_owners`: a list of emails (compared case-insensitively; the signed-in account must also have a verified email).
3. With neither, everyone is denied.

Being a workspace owner or admin grants nothing here.

The check goes through the Gate, so a host `Gate::before` callback that returns `true` (a super-admin bypass, Spatie's included) also grants this ability. If super-admins should not manage tenants, return `null` for it in your callback:

```php
Gate::before(fn ($user, $ability) => $ability === 'concierge.manage-tenants' ? null : ($user->isSuperAdmin() ?: null));
```

### What the host owns

Concierge ships **no routes** for this. You provide:

- **The workspace.** Tell Concierge how to create the saved workspace; it runs inside the acceptance transaction, and the invitee is then established as its owner through `EstablishWorkspace`. If the provisioner throws, nothing is written and the invitation stays pending.

  ```php
  Concierge::provisionWorkspaceUsing(fn (User $owner, TenantInvitation $invitation) => Workspace::create([
      'name' => $owner->name."'s workspace",
  ]));
  ```

- **The link.** `Concierge::tenantInvitationUrlUsing(fn (TenantInvitation $invitation, string $token) => route('join', $token))`. Without it nothing is emailed (you can still read the token from `InviteTenant`'s result).
- **Register-or-sign-in.** Call `InspectTenantInvitation` on the link, lock your register form to the invitation email, sign the user in, then call `AcceptTenantInvitation` and redirect into their new workspace. Accepting requires the account email to match the invitation and to be **verified**. Opening the invitation proves control of the inbox, so your registration may mark the email verified when it comes from a valid, pending invitation for that same email; Concierge does not do that for you.
- **Tenant-less routes.** The inspect and accept routes must sit outside any tenant or `concierge.member` middleware: there is no workspace yet.
- **Throttling.** Concierge defines named limiters; apply them to your routes: `throttle:concierge-tenant-invitation-inspect`, `throttle:concierge-tenant-invitation-accept` (per IP, per token and per signed-in user) and `throttle:concierge-tenant-invitation-invite`. The token limiters read a route parameter named `token`. Limits are in `tenant_invitations.throttle`.

### Multitenancy hosts

`Tey\Concierge\Jobs\DeliverTenantInvitation` is deliberately not tenant-aware (an invitation exists before any workspace) and resolves everything by id. A host using `spatie/laravel-multitenancy` with tenant-aware queues **must** list it under `not_tenant_aware_jobs`, or the queue layer deletes the job for lacking a tenant and the email is silently lost:

```php
// config/multitenancy.php
'not_tenant_aware_jobs' => [Tey\Concierge\Jobs\DeliverTenantInvitation::class],
```

Events (`TenantInvited`, `TenantInvitationResent`, `TenantInvitationRevoked`, `TenantInvitationAccepted`) are dispatched after commit and carry ids only, never a model or an ambient tenant. A listener that needs the workspace (`TenantInvitationAccepted::$workspaceId`) must enter its context explicitly.

## Local login

One click signs in as an account named in configuration, creating it on the first click. It exists for local development, where typing a password into a throwaway account is friction.

```php
// config/concierge.php
'local_login' => [
    'enabled' => true,
    'account' => ['email' => 'dev@example.test', 'name' => 'Local developer'],
],
```

It is available only when **all** of these hold, checked on every request:

| Check | Must be | Read from |
| --- | --- | --- |
| Laravel's environment | exactly `local` | the configuration cache if there is one, otherwise `.env` or the process |
| `APP_ENV` of the process serving the request | exactly `local` | `getenv`, then `$_SERVER` only when the process has none; never `$_ENV` or a cache |
| `concierge.local_login.enabled` | `true` | configuration |
| `concierge.local_login.account.email` | not empty | configuration |

Exact means exact: `Local`, ` local ` and an empty value all fail. When any check fails there is no offer, and `POST /local-login` answers 404 before the session starts and before CSRF verification, the guest check and the throttle, even when the route came from a cache built in `local`. The route is registered only when both environments are `local`. This holds under php-fpm, `php artisan serve`, long-lived workers such as Octane (the check runs per request, not per boot) and the console.

**Developing with cached configuration** (`config:cache` or `optimize`): Laravel stops reading `.env`, so a local configuration cache alone is not enough. Set `APP_ENV=local` in the environment of the process that serves requests, such as your shell for `php artisan serve` or `env[APP_ENV]` in a php-fpm pool. Without it local login is off. A non-local process value always wins over `$_SERVER`, and Concierge never changes the application's environment.

What it does and does not do:

- `POST /local-login` (route `concierge.local-login.store`), with CSRF protection, the `guest` middleware for the configured guard and a per-IP throttle (`throttle`, default 10 a minute).
- The identity comes from configuration only; anything the browser sends is ignored.
- A new account gets a random password nobody knows, a verified address if the table has `email_verified_at`, and a name if it has `name`. `account.attributes` can fill other required columns but never the identity or password.
- An existing account is signed in exactly as it is: nothing is saved, and its password, name, verification and roles are untouched. A soft-deleted match is refused, not restored.
- It **bypasses the password and two-factor** for that account, and nothing else: it grants no roles, memberships or workspace, and it does not mark the session as recently authenticated.
- It signs in on `concierge.local_login.guard` (default: the application default guard), and that guard must use an Eloquent user provider.
- After signing in it redirects to the intended URL, then `concierge.local_login.redirect`, then `/`. Set `redirect` to your home path if it is not `/`.
- No remember token is set, and the session ID and CSRF token are regenerated.

The host decides where the button appears. Pass the offer to your login page and render `LocalLoginButton` (see [resources/js/README.md](resources/js/README.md)):

```php
use Tey\Concierge\LocalAuth\LocalLogin;

// null unless local login is available right now
'localLogin' => app(LocalLogin::class)->offer(),
```

### Without the membership feature

An app that wants only local login, with no workspaces or permission tables, turns membership off:

```php
// config/concierge.php
'features' => ['membership' => false],
```

With that set, Concierge registers no membership bindings, no `concierge.member` middleware alias, no `concierge:sync-roles` command, and **does not load its migration**, so `php artisan migrate` needs no workspace or permission setup. Keep the default (`true`) for membership apps.

The switch is read while the package provider registers, so set it in `config/concierge.php`, not at runtime.

Existing user-management features such as impersonation, deactivation and forced password reset are outside Concierge and are not replaced by it.

## Vue UI

Optional reusable Vue 3 components, including the invitation and membership pages and `LocalLoginButton`. The PHP code never depends on them. See [resources/js/README.md](resources/js/README.md).

## Testing

```bash
composer test
composer analyse
composer format
npm run types:check
npm run consumer:proof
```

## Credits

- Scaffolded from [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
- [Jasper Tey](https://github.com/jaspertey)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
