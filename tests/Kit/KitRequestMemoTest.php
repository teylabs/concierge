<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tey\Concierge\Contracts\WorkspaceContext;
use Workbench\App\Kit\Team;
use Workbench\App\Kit\User;

function kit_memo_queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
}

beforeEach(function () {
    $this->olive = User::query()->create(['name' => 'Olive', 'email' => 'olive@example.test', 'email_verified_at' => now()]);
    $this->acme = Team::query()->create(['name' => 'Acme']);
    $this->other = Team::query()->create(['name' => 'Other']);
    $this->olive->forceFill(['current_team_id' => $this->acme->id])->save();
    app()->forgetScopedInstances();
});

it("reads the user's current team once per request", function () {
    $this->actingAs($this->olive);
    $context = app(WorkspaceContext::class);

    // Before this change each call ran one query: five here.
    expect(kit_memo_queries(function () use ($context) {
        foreach (range(1, 5) as $ignored) {
            expect($context->current()?->conciergeName())->toBe('Acme');
        }
    }))->toBe(1);
});

it('reads the route team once per request', function () {
    Route::get('/{current_team}/memo', function () {
        $context = app(WorkspaceContext::class);
        $count = kit_memo_queries(fn () => array_map(fn () => $context->current()?->conciergeName(), range(1, 5)));

        return $context->current()?->conciergeName().':'.$count;
    });

    $this->actingAs($this->olive)->get("/{$this->other->slug}/memo")->assertSee('Other:1');
});

it('switches to and restores the running team, reading the running team inside run()', function () {
    $this->actingAs($this->olive);
    $context = app(WorkspaceContext::class);

    expect($context->current()?->conciergeName())->toBe('Acme')
        ->and($context->run($this->other, fn () => [$context->current()?->conciergeName(), $context->run($this->acme, fn () => $context->current()?->conciergeName())]))->toBe(['Other', 'Acme'])
        ->and($context->current()?->conciergeName())->toBe('Acme');

    expect(fn () => $context->run($this->other, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and($context->current()?->conciergeName())->toBe('Acme');
});

it("follows a change to the user's current team in the same request", function () {
    $this->actingAs($this->olive);
    $context = app(WorkspaceContext::class);
    expect($context->current()?->conciergeName())->toBe('Acme');

    $this->olive->forceFill(['current_team_id' => $this->other->id])->save();

    expect($context->current()?->conciergeName())->toBe('Other');
});

it('reads fresh after the team row is written', function () {
    $this->actingAs($this->olive);
    $context = app(WorkspaceContext::class);
    expect($context->current()?->conciergeName())->toBe('Acme');

    $this->acme->forceFill(['name' => 'Acme Travel'])->save();

    expect($context->current()?->conciergeName())->toBe('Acme Travel');
});
