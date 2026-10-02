# Concierge

Invitations, membership, and access administration for Laravel.

Concierge is a private Teylabs package with two independent features:

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

Concierge is distributed from the private `teylabs/concierge` GitHub repository. Add it as a VCS repository and require it:

```bash
composer config repositories.concierge vcs https://github.com/teylabs/concierge
composer require tey/concierge
```

The service provider is registered through Laravel package discovery.

## Membership

### Order matters

The package registers its migration automatically, and that migration reads your user and workspace models and tables. So, before `php artisan migrate`:

1. Create your user and workspace tables and models.
2. Publish and migrate the Spatie permission tables with teams enabled.
3. Publish the config and set `concierge.models.user` and `concierge.models.workspace`.
4. Run `php artisan migrate`, then `php artisan concierge:sync-roles` to create the configured roles.

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

Hiding a control in the UI is not authorization: enforce every action on the server.

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

- the application environment is exactly `local`
- `concierge.local_login.enabled` is `true`
- an account email is configured

Otherwise there is no route and no button, and a route cached in `local` and served elsewhere answers 404 before CSRF verification, the guest check and the throttle.

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
