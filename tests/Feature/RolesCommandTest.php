<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('refuses app-wide role changes in workspace mode, naming concierge.mode', function (bool $json) {
    host_user('owner');
    host_user('ana');

    expect(Artisan::call('concierge:roles', [
        'email' => 'ana@example.test',
        '--role' => ['presenter'],
        '--as' => 'owner@example.test',
        '--json' => $json,
    ]))->toBe(1);

    $output = Artisan::output();
    if ($json) {
        $error = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($error['code'])->toBe('unsupported_configuration');
        $output = $error['error'];
    }
    expect($output)->toContain('concierge.mode')
        ->and(DB::table('model_has_roles')->count())->toBe(0);
})->with([true, false]);
