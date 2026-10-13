<?php

use Illuminate\Support\Facades\Artisan;

it('refuses in workspace mode and names concierge.mode', function (?bool $json) {
    expect(Artisan::call('concierge:invite', [
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => 'owner@example.com',
        '--json' => $json,
    ]))->not->toBe(0);

    $output = Artisan::output();
    if ($json) {
        $output = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error'];
    }
    expect($output)->toContain('concierge.mode');
})->with([true, false]);
