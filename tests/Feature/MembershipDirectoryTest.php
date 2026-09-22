<?php

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\BatchesMembershipReads;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace as WorkspaceContract;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConfiguredGrantPolicy;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\MembershipDirectory;

function decision(Response $response): array
{
    return [$response->allowed(), $response->code()];
}

function count_queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

beforeEach(function () {
    $this->concierge = app(Concierge::class);
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->secondAdmin = host_user('second-admin');
    $this->presenter = host_user('presenter');
    $this->suspended = host_user('suspended');
    $this->removed = host_user('removed');
    $this->outsider = host_user('outsider');

    join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    join_as($this->workspace, $this->owner, $this->secondAdmin, ['admin']);
    join_as($this->workspace, $this->owner, $this->presenter, ['presenter']);
    SuspendMembership::run($this->owner, join_as($this->workspace, $this->owner, $this->suspended, ['presenter']));
    RemoveMembership::run($this->owner, join_as($this->workspace, $this->owner, $this->removed, ['presenter']));

    $this->foreign = host_workspace('Globex', $foreignOwner = host_user('foreign-owner'));
    $this->foreignMembership = join_as($this->foreign, $foreignOwner, host_user('foreigner'), ['presenter']);
});

function memberships_of(WorkspaceContract $workspace): array
{
    return Membership::query()->with('user')->where('workspace_id', $workspace->getKey())->orderBy('id')->get()->all();
}

it('decides exactly like the live policy for every actor and target', function (bool $preloadPage) {
    $live = app(GrantPolicy::class);
    $members = memberships_of($this->workspace);
    $actors = [$this->owner, $this->admin, $this->secondAdmin, $this->presenter, $this->suspended, $this->removed, $this->outsider];
    $targets = [null, ...$members, $this->foreignMembership];

    foreach ($actors as $actor) {
        $directory = $this->concierge->directory($this->workspace, $actor, $preloadPage ? $members : []);
        $batched = $this->concierge->policyFor($directory);

        expect($batched->grantableRoles($actor, $this->workspace))->toBe($live->grantableRoles($actor, $this->workspace));

        foreach ($targets as $target) {
            expect(decision($batched->authorizeManagement($actor, $this->workspace, $target)))
                ->toBe(decision($live->authorizeManagement($actor, $this->workspace, $target)));

            foreach ([['presenter'], ['admin'], ['owner']] as $roles) {
                expect(decision($batched->authorizeGrant($actor, $this->workspace, new GrantSet($roles), $target)))
                    ->toBe(decision($live->authorizeGrant($actor, $this->workspace, new GrantSet($roles), $target)));
            }
        }

        foreach ($members as $member) {
            expect($directory->roleNames($member))->toBe($this->concierge->roleNames($member))
                ->and($directory->isOwner($member))->toBe($this->concierge->isOwner($member));
        }
    }
})->with(['page preloaded' => true, 'nothing preloaded (live fallback)' => false]);

it('answers a page in a fixed number of queries regardless of its size', function () {
    $render = function (array $members): int {
        return count_queries(function () use ($members) {
            $directory = $this->concierge->directory($this->workspace, $this->admin, $members);
            $policy = $this->concierge->policyFor($directory);

            foreach ($members as $member) {
                $policy->authorizeManagement($this->admin, $this->workspace, $member);
                $policy->authorizeGrant($this->admin, $this->workspace, new GrantSet(['presenter']));
                $directory->roleNames($member);
                $directory->isOwner($member);
            }

            $directory->owner();
        });
    };

    $live = fn (array $members) => count_queries(function () use ($members) {
        foreach ($members as $member) {
            app(GrantPolicy::class)->authorizeManagement($this->admin, $this->workspace, $member);
        }
    });

    $small = memberships_of($this->workspace);
    $render($small);

    foreach (range(1, 10) as $i) {
        join_as($this->workspace, $this->owner, host_user("extra{$i}"), ['presenter']);
    }
    $large = memberships_of($this->workspace);

    expect(count($large))->toBe(count($small) + 10)
        ->and($render($large))->toBe($render($small))
        ->and($render($large))->toBeLessThanOrEqual(3)
        ->and($live($large))->toBeGreaterThan($live($small));
});

it('reads members outside the snapshot live instead of treating them as absent', function () {
    $directory = $this->concierge->directory($this->workspace, $this->owner);
    $presenterMembership = $this->presenter->membershipIn($this->workspace);

    $queries = count_queries(function () use ($directory, $presenterMembership) {
        expect($directory->membershipOf($this->presenter)?->is($presenterMembership))->toBeTrue()
            ->and($directory->roleNames($presenterMembership))->toBe(['presenter'])
            ->and($directory->membershipOf($this->outsider))->toBeNull();
    });

    expect($queries)->toBeGreaterThan(0)
        ->and($directory->membershipOf($this->owner)?->user_id)->toBe($this->owner->id);
});

it('never answers for another workspace from the snapshot', function () {
    $directory = $this->concierge->directory($this->workspace, $this->owner, memberships_of($this->workspace));
    $policy = $this->concierge->policyFor($directory);
    $foreignOwner = $this->foreign->owner()->user;

    expect($directory->covers($this->foreign))->toBeFalse()
        ->and(decision($policy->authorizeManagement($foreignOwner, $this->foreign, $this->foreignMembership)))
        ->toBe(decision(app(GrantPolicy::class)->authorizeManagement($foreignOwner, $this->foreign, $this->foreignMembership)))
        ->and($policy->grantableRoles($foreignOwner, $this->foreign))->toBe(['admin', 'presenter'])
        ->and(fn () => $directory->roleNames($this->foreignMembership))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $directory->isOwner($this->foreignMembership))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->concierge->directory($this->workspace, $this->owner, [$this->foreignMembership]))
        ->toThrow(InvalidArgumentException::class);
});

it('is a per-call snapshot that never leaks into the container or live decisions', function () {
    $presenterMembership = $this->presenter->membershipIn($this->workspace);
    $before = $this->concierge->directory($this->workspace, $this->owner, [$presenterMembership]);
    $batched = $this->concierge->policyFor($before);

    ChangeMemberRoles::run($this->owner, $presenterMembership, new GrantSet(['admin']));

    $after = $this->concierge->directory($this->workspace, $this->owner, [$presenterMembership]);
    $resolved = app(GrantPolicy::class);

    expect($before->roleNames($presenterMembership))->toBe(['presenter'])
        ->and($after->roleNames($presenterMembership))->toBe(['admin'])
        ->and($after)->not->toBe($before)
        ->and(app()->bound(MembershipDirectory::class))->toBeFalse()
        ->and($batched)->not->toBe($resolved)
        ->and((fn () => $this->directory)->call($resolved))->toBeNull()
        ->and(decision($resolved->authorizeManagement($this->admin, $this->workspace, $presenterMembership)))->toBe([false, 'rank']);
});

it('returns custom policies untouched', function () {
    $custom = new class implements GrantPolicy
    {
        public function grantableRoles(Member $actor, WorkspaceContract $workspace): array
        {
            return [];
        }

        public function authorizeManagement(Member $actor, WorkspaceContract $workspace, ?Membership $target = null): Response
        {
            return Response::deny('Custom rule.', 'custom');
        }

        public function authorizeGrant(Member $actor, WorkspaceContract $workspace, GrantSet $grants, ?Membership $target = null): Response
        {
            return Response::deny('Custom rule.', 'custom');
        }
    };
    app()->instance(GrantPolicy::class, $custom);

    $policy = $this->concierge->policyFor($this->concierge->directory($this->workspace, $this->owner));

    expect($policy)->toBe($custom)
        ->and(decision($policy->authorizeManagement($this->owner, $this->workspace)))->toBe([false, 'custom'])
        ->and(new ConfiguredGrantPolicy($this->concierge))->toBeInstanceOf(BatchesMembershipReads::class);
});
