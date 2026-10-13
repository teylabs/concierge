<?php

use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Support\AppScope;
use Workbench\App\Models\User;

require __DIR__.'/../Support/OwnersCommandTests.php';

it('creates several app owners and ignores duplicate or blank configured emails', function () {
    config()->set('concierge.owners', [' First@example.test ', '', 'first@EXAMPLE.TEST', 'second@example.test', ' ']);

    $this->artisan('concierge:owners')->assertSuccessful();

    expect(app(AppScope::class)->ownerIds())->toHaveCount(2)
        ->and(User::query()->count())->toBe(2);
    Notification::assertCount(2);
});
