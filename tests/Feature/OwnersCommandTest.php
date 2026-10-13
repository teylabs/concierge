<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Concierge;
use Workbench\App\Models\User;

require __DIR__.'/../Support/OwnersCommandTests.php';

it('refuses a missing or unknown --workspace before creating users', function (?string $key) {
    expect(Artisan::call('concierge:owners', $key === null ? [] : ['--workspace' => $key]))->not->toBe(0)
        ->and(Artisan::output())->toContain('--workspace')
        ->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([null, '999999', 'not-a-key', '999999999999999999999999']);

it('keeps an existing workspace owner and refuses a conflicting owner without creating a user', function () {
    $current = host_user('current');
    EstablishWorkspace::run($this->ownerWorkspace, $current);

    expect(Artisan::call('concierge:owners', $this->ownerOptions + ['--json' => true]))->not->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('--workspace', 'concierge.owners', 'TransferOwnership::run()')
        ->and(app(Concierge::class)->ownerOf($this->ownerWorkspace)->user_id)->toBe($current->id)
        ->and(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('refuses several owners for one workspace before writing anything', function () {
    config()->set('concierge.owners', ['first@example.test', 'second@example.test']);

    expect(Artisan::call('concierge:owners', $this->ownerOptions))->not->toBe(0)
        ->and(Artisan::output())->toContain('--workspace')
        ->and(User::query()->count())->toBe(0)
        ->and(app(Concierge::class)->ownerOf($this->ownerWorkspace))->toBeNull();
    Notification::assertNothingSent();
});
