# Concierge

Invitations, membership, and access administration for Laravel.

Concierge is a private Teylabs package. It is in its scaffold stage: the service provider currently registers only the package config. Invitation and membership features will be documented here once they land.

## Requirements

- PHP 8.4 or 8.5
- Laravel 13
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) 8.x
- [lorisleiva/laravel-actions](https://laravelactions.com) 2.x

## Installation

Concierge is distributed from the private `teylabs/concierge` GitHub repository. Add it as a VCS repository and require it:

```bash
composer config repositories.concierge vcs https://github.com/teylabs/concierge
composer require tey/concierge
```

The service provider is registered through Laravel package discovery.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="concierge-config"
```

Published defaults:

```php
return [
    'invitations' => [
        'expires_after_days' => 7,
    ],
];
```

Values in the host application's `config/concierge.php` take precedence over the package defaults.

## Testing

```bash
composer test
composer analyse
composer format
```

## Credits

- Scaffolded from [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
- [Jasper Tey](https://github.com/jaspertey)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
