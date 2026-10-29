<?php

use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\RequestMemo;

/** Queries run by $callback, as their SQL. */
function memo_queries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
    }
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->acme = host_workspace('Acme', $this->owner);
    $this->adam = host_user('adam');
    $this->membership = join_as($this->acme, $this->owner, $this->adam, ['presenter']);
    $other = host_workspace('Other', $this->adam);
    join_as($other, $this->adam, $this->owner, ['presenter']);

    // A new request starts with an empty memo.
    app()->forgetScopedInstances();
});

it('reads a member once per request: one membership, one ownership and one role query', function () {
    $queries = memo_queries(function () {
        foreach (range(1, 5) as $ignored) {
            expect(app(MembershipSource::class)->member($this->acme, $this->adam)?->roles)->toBe(['presenter']);
        }
    });

    // Before this change five calls ran twenty queries (four each).
    expect($queries)->toHaveCount(4)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'concierge_memberships'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'concierge_ownerships'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'model_has_roles'))->count())->toBe(1);
});

it('reads workspacesOf once per request, with one role query per workspace', function () {
    $queries = memo_queries(function () {
        foreach (range(1, 5) as $ignored) {
            expect(app(MembershipSource::class)->workspacesOf($this->owner))->toHaveCount(2);
        }
    });

    // membership + eager workspaces + ownership + one role read per workspace.
    expect($queries)->toHaveCount(5);
});

it('keeps scoped-guest and plain member lists apart', function () {
    $source = app(MembershipSource::class);

    expect($source->members($this->acme))->toHaveCount(2)
        ->and(memo_queries(fn () => $source->includingScopedGuests()->members($this->acme)))->not->toBeEmpty();
});

it('reads fresh after a role change in the same request', function () {
    $source = app(MembershipSource::class);
    expect($source->member($this->acme, $this->adam)?->roles)->toBe(['presenter']);

    ChangeMemberRoles::run($this->owner, $this->membership, new GrantSet(['admin']));

    expect($source->member($this->acme, $this->adam)?->roles)->toBe(['admin']);
});

it('reads fresh after a membership change in the same request', function () {
    $source = app(MembershipSource::class);
    expect($source->member($this->acme, $this->adam))->not->toBeNull();

    SuspendMembership::run($this->owner, $this->membership);

    expect($source->member($this->acme, $this->adam))->toBeNull();
});

it('reads fresh after an ownership write, even one through the query builder', function () {
    $source = app(MembershipSource::class);
    expect($source->member($this->acme, $this->adam)?->owner)->toBeFalse();

    DB::table('concierge_ownerships')->where('workspace_id', $this->acme->getKey())->update(['membership_id' => $this->membership->getKey()]);

    expect($source->member($this->acme, $this->adam)?->owner)->toBeTrue();
});

it('forgets reads made inside a transaction that rolls back', function () {
    $source = app(MembershipSource::class);

    DB::beginTransaction();
    DB::table('concierge_ownerships')->where('workspace_id', $this->acme->getKey())->update(['membership_id' => $this->membership->getKey()]);
    expect($source->member($this->acme, $this->adam)?->owner)->toBeTrue();
    DB::rollBack();

    expect($source->member($this->acme, $this->adam)?->owner)->toBeFalse();
});

it('never carries a read into the next request', function () {
    $source = app(MembershipSource::class);
    expect($source->member($this->acme, $this->adam)?->roles)->toBe(['presenter']);

    // A write this process does not see, as another app instance would make it.
    $connection = DB::connection();
    $events = $connection->getEventDispatcher();
    $connection->unsetEventDispatcher();

    try {
        $deleted = DB::table('model_has_roles')->where('model_id', $this->adam->getKey())->where('workspace_id', $this->acme->getKey())->delete();
    } finally {
        $connection->setEventDispatcher($events);
    }

    expect($deleted)->toBe(1);

    expect(app(MembershipSource::class)->member($this->acme, $this->adam)?->roles)->toBe(['presenter']);

    app()->forgetScopedInstances();

    expect(app(RequestMemo::class)->isEmpty())->toBeTrue()
        ->and(app(MembershipSource::class)->member($this->acme, $this->adam)?->roles)->toBe([]);
});
