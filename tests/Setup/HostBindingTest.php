<?php

use Tey\Concierge\Concierge;
use Tey\Concierge\LocalAuth\LocalLogin;
use Tey\Concierge\Tests\Setup\Fixtures\HostBindingProvider;

it('keeps a host binding made at register over the ones concierge makes at boot', function () {
    expect(app(Concierge::class))->toBe(HostBindingProvider::$concierge)
        ->and(app(LocalLogin::class))->toBe(HostBindingProvider::$localLogin);
});
