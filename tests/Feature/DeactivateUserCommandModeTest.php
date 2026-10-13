<?php

use Illuminate\Support\Facades\Artisan;

it('refuses to deactivate a user in workspace mode, naming concierge.mode', function () {
    $owner = host_user('owner');
    host_workspace(owner: $owner);

    expect(Artisan::call('concierge:deactivate', ['email' => $owner->email, '--as' => $owner->email, '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => "concierge.mode = 'workspaces' scopes roles by workspace; concierge:deactivate is app-wide only; in workspace mode deactivate the user through your own AccountPolicy, or set 'mode' => 'app' in config/concierge.php.",
            'code' => 'unsupported_configuration',
        ]);

    Artisan::call('concierge:deactivate', ['email' => $owner->email, '--as' => $owner->email, '--no-interaction' => true]);

    expect(Artisan::output())->toContain('concierge.mode');
});
