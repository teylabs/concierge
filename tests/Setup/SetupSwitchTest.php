<?php

use Illuminate\Support\Facades\File;
use Tey\Concierge\ConciergeServiceProvider;

afterEach(function () {
    File::delete(config_path('concierge.php'));
    config()->offsetUnset('concierge.setup');
});

it('lets the switch force either state', function () {
    config()->set('concierge.setup', true);
    expect(ConciergeServiceProvider::setUp())->toBeTrue();

    File::put(config_path('concierge.php'), "<?php\n\nreturn [];\n");
    config()->set('concierge.setup', false);
    expect(ConciergeServiceProvider::setUp())->toBeFalse();
});

it('falls back to the config file when the switch is not a boolean', function () {
    config()->set('concierge.setup', null);
    expect(ConciergeServiceProvider::setUp())->toBeFalse();

    File::put(config_path('concierge.php'), "<?php\n\nreturn [];\n");
    expect(ConciergeServiceProvider::setUp())->toBeTrue();
});
