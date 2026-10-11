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

The full documentation is at [concierge.teylabs.com](https://concierge.teylabs.com). What is planned on the way to 1.0 is on [What concierge covers](https://concierge.teylabs.com/roadmap).

## Installation

Concierge requires PHP 8.4 or 8.5 and Laravel 13.

```bash
composer require tey/concierge
```

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
3. Set the two models in `config/concierge.php`:

    ```php
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

## Usage

### Membership

Every operation is an action class in `Tey\Concierge\Actions`, called with `::run()`: invite, resend, revoke and accept invitations; change roles; suspend, reinstate and remove members; transfer ownership. Actions check the grant rules in `config/concierge.php` and dispatch events. Your app keeps its routes, controllers, policies and authentication, and protects routes with the `concierge.member` middleware. See [Membership](https://concierge.teylabs.com/features/membership).

### Tenant Invitations

For an invite-only app: a platform owner invites an email, and accepting creates that person's own workspace, which they own. It is off by default and ships no routes; your app provides the workspace factory, the link and the register-or-sign-in pages. See [Tenant invitations](https://concierge.teylabs.com/features/tenant-invitations).

### Local Login

One click signs in as the account in your configuration, in the `local` environment only, checked on every request. It works without membership. See [Local login](https://concierge.teylabs.com/features/local-login) for the exact rules, including cached configuration.

### Vue Pages

Optional Vue 3 pages (`MembersPage`, `InviteMemberPage`, `MemberPage`, `AcceptInvitationPage`) and `LocalLoginButton` ship in the package under `resources/js`. The PHP code never depends on them. See [Vue pages](https://concierge.teylabs.com/features/vue-pages) for the Vite, Tailwind and plugin setup.

## Configuration

The published `config/concierge.php` lists every key with a comment on each: the models, the roles and who may grant them, invitation expiry, tenant invitations and local login. Each feature page explains the keys it uses.

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
