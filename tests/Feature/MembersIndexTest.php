<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\MembersIndex;

/** The index for $actor, as the request's query string asks for it. */
function members_index(object $actor, object $workspace, array $query = [], bool $urls = true): array
{
    $builder = app(MembersIndex::class);

    if ($urls) {
        $builder->urlsUsing(fn (string $action, $subject) => '/'.$action.($subject ? '/'.($subject->public_id ?? $subject->getKey()) : ''));
    }

    return $builder->props($actor, $workspace, Request::create('/members', 'GET', $query))->toArray();
}

/** @return list<string> */
function member_names(array $props): array
{
    return array_map(fn (array $row) => $row['user']['name'], $props['members']);
}

function action_keys(array $row): array
{
    return array_column($row['actions'], 'key');
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->adminMembership = join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    $this->second = host_user('second-admin');
    $this->secondMembership = join_as($this->workspace, $this->owner, $this->second, ['admin']);
    $this->ana = host_user('ana');
    $this->anaMembership = join_as($this->workspace, $this->owner, $this->ana, ['presenter']);
    $this->bo = host_user('bo');
    $this->boMembership = join_as($this->workspace, $this->owner, $this->bo, ['presenter']);
    $this->anaMembership->forceFill(['joined_at' => now()->subDays(3)])->save();
    $this->boMembership->forceFill(['joined_at' => now()->subDays(1)])->save();
    issue($this->owner, $this->workspace, 'cy@example.test', ['presenter']);
    issue($this->owner, $this->workspace, 'dee@example.test', ['admin']);
});

it('builds the members tab: rows, tabs with counts, columns, pagination and the query state', function () {
    $props = members_index($this->owner, $this->workspace);

    expect($props['tab'])->toBe('members')
        ->and(member_names($props))->toBe(['Admin', 'Ana', 'Bo', 'Owner', 'Second-admin'])
        ->and(array_column($props['tabs'], 'key'))->toBe(['members', 'invitations'])
        ->and(array_column($props['tabs'], 'count'))->toBe([5, 2])
        ->and(array_column($props['tabs'], 'current'))->toBe([true, false])
        ->and($props['tabs'][1]['url'])->toBe('/index?tab=invitations')
        ->and($props['table']['rows'])->toEqual($props['members'])
        ->and(array_column($props['table']['columns'], 'key'))->toBe(['member', 'roles', 'status', 'joined', 'actions'])
        ->and($props['table']['sort'])->toBe(['column' => 'member', 'direction' => 'asc'])
        ->and($props['table']['pagination'])->toBe(['page' => 1, 'perPage' => 25, 'total' => 5, 'from' => 1, 'to' => 5, 'lastPage' => 1])
        ->and($props['table']['emptyState'])->toBeNull()
        ->and($props['invitations'])->toBe([])
        ->and($props['members'][3]['isOwner'])->toBeTrue()
        ->and($props['members'][3]['roles'][0]['label'])->toBe('Owner')
        ->and($props['metadata']['abilities'])->toBe(['invite' => true, 'manageMembers' => true, 'transferOwnership' => true])
        ->and($props['metadata']['links']['invite'])->toBe('/invite');
});

it('sorts, searches and filters through the query string, and an unknown sort, filter or tab is a 400', function () {
    $joined = members_index($this->owner, $this->workspace, ['sort' => '-joined']);
    $name = members_index($this->owner, $this->workspace, ['sort' => '-name']);

    expect(member_names($joined)[0])->not->toBe('Ana')
        ->and(array_search('Bo', member_names($joined)))->toBeLessThan(array_search('Ana', member_names($joined)))
        ->and($joined['table']['sort'])->toBe(['column' => 'joined', 'direction' => 'desc'])
        ->and(member_names($name))->toBe(['Second-admin', 'Owner', 'Bo', 'Ana', 'Admin'])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'an']])))->toBe(['Ana'])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'bo@example']])))->toBe(['Bo'])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['role' => 'admin']])))->toBe(['Admin', 'Second-admin'])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['role' => 'presenter']])))->toBe(['Ana', 'Bo'])
        ->and(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'an', 'role' => 'admin']])['table']['emptyState'])->toBe('no-results');

    expect(fn () => members_index($this->owner, $this->workspace, ['sort' => 'password']))->toThrow(InvalidSortQuery::class)
        ->and(fn () => members_index($this->owner, $this->workspace, ['filter' => ['email' => 'x']]))->toThrow(InvalidFilterQuery::class)
        ->and(fn () => members_index($this->owner, $this->workspace, ['tab' => 'bots']))->toThrow(HttpException::class);

    foreach ([fn () => members_index($this->owner, $this->workspace, ['sort' => 'password']), fn () => members_index($this->owner, $this->workspace, ['tab' => 'bots'])] as $bad) {
        try {
            $bad();
        } catch (Throwable $e) {
            expect($e->getStatusCode())->toBe(400);
        }
    }
});

it('pages with a capped per-page size and reports the range', function () {
    $props = members_index($this->owner, $this->workspace, ['per_page' => 2, 'page' => 2]);

    expect($props['table']['pagination'])->toBe(['page' => 2, 'perPage' => 2, 'total' => 5, 'from' => 3, 'to' => 4, 'lastPage' => 3])
        ->and(member_names($props))->toBe(['Bo', 'Owner'])
        ->and($props['pagination']['currentPage'])->toBe(2)
        ->and(members_index($this->owner, $this->workspace, ['per_page' => 5000])['table']['pagination']['perPage'])->toBe(100)
        ->and(members_index($this->owner, $this->workspace, ['per_page' => 0])['table']['pagination']['perPage'])->toBe(1);
});

it('keeps a comma in a search or role as one value, and ignores an array value instead of returning a 500', function () {
    expect(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'Ana, Bo']])['table']['emptyState'])->toBe('no-results')
        ->and(members_index($this->owner, $this->workspace, ['filter' => ['role' => 'Viewer, Owner']])['table']['emptyState'])->toBe('no-results')
        ->and(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'filter' => ['search' => 'dee, cy']])['invitations'])->toBe([])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => ['an']]])))->toBe(member_names(members_index($this->owner, $this->workspace)))
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['role' => ['admin']]])))->toBe(member_names(members_index($this->owner, $this->workspace)))
        ->and(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'filter' => ['search' => ['dee']]])['invitations'])->toHaveCount(2);

    expect(members_index($this->owner, $this->workspace, ['sort' => ['-joined']])['table']['sort'])->toBe(['column' => 'member', 'direction' => 'asc'])
        ->and(fn () => members_index($this->owner, $this->workspace, ['tab' => ['members']]))->toThrow(HttpException::class);
});

it('counts the tabs from the server, following the active search and nothing else', function () {
    $searched = members_index($this->owner, $this->workspace, ['filter' => ['search' => 'cy', 'role' => 'admin'], 'sort' => '-joined']);

    expect(array_column($searched['tabs'], 'count'))->toBe([0, 1])
        ->and($searched['tabs'][1]['url'])->toBe('/index?tab=invitations&filter%5Bsearch%5D=cy')
        ->and(array_column(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'an']])['tabs'], 'count'))->toBe([1, 0])
        ->and(array_column(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'example']])['tabs'], 'count'))->toBe([5, 2]);
});

it('builds the invitations tab, with its own sorts and the actions rank allows', function () {
    $props = members_index($this->owner, $this->workspace, ['tab' => 'invitations']);

    expect($props['tab'])->toBe('invitations')
        ->and(array_column($props['invitations'], 'email'))->toBe(['cy@example.test', 'dee@example.test'])
        ->and($props['members'])->toBe([])
        ->and(array_column($props['table']['columns'], 'key'))->toBe(['email', 'roles', 'invitedBy', 'sent', 'expires', 'actions'])
        ->and($props['invitations'][0]['invitedBy'])->toBe('Owner')
        ->and($props['invitations'][0]['status'])->toBe('pending')
        ->and(action_keys($props['invitations'][0]))->toBe(['resend', 'copy_link', 'revoke'])
        ->and($props['invitations'][0]['actions'][2]['destructive'])->toBeTrue()
        ->and($props['invitations'][0]['actions'][2]['confirm']['confirmLabel'])->toBe('Revoke invitation')
        ->and(array_column(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'sort' => '-email'])['invitations'], 'email'))->toBe(['dee@example.test', 'cy@example.test'])
        ->and(array_column(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'filter' => ['search' => 'dee']])['invitations'], 'email'))->toBe(['dee@example.test']);

    // An admin may resend what they could have sent (presenter), but not an admin invitation; both can be revoked.
    $asAdmin = members_index($this->admin, $this->workspace, ['tab' => 'invitations']);

    expect(action_keys($asAdmin['invitations'][0]))->toBe(['resend', 'copy_link', 'revoke'])
        ->and(action_keys($asAdmin['invitations'][1]))->toBe(['revoke']);

    expect(fn () => members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'sort' => 'name']))->toThrow(InvalidSortQuery::class);
});

it('decides row actions by rank: an admin has none on the owner or another admin, and nobody manages themselves', function () {
    $actions = fn (array $props) => collect($props['members'])->mapWithKeys(fn (array $row) => [$row['user']['name'] => action_keys($row)])->all();

    expect($actions(members_index($this->owner, $this->workspace)))->toBe([
        'Admin' => ['change-role', 'suspend', 'transfer-ownership', 'remove'],
        'Ana' => ['change-role', 'suspend', 'transfer-ownership', 'remove'],
        'Bo' => ['change-role', 'suspend', 'transfer-ownership', 'remove'],
        'Owner' => [],
        'Second-admin' => ['change-role', 'suspend', 'transfer-ownership', 'remove'],
    ]);

    $asAdmin = members_index($this->admin, $this->workspace);

    expect($actions($asAdmin))->toBe(['Admin' => [], 'Ana' => ['change-role', 'suspend', 'remove'], 'Bo' => ['change-role', 'suspend', 'remove'], 'Owner' => [], 'Second-admin' => []])
        ->and($asAdmin['metadata']['abilities'])->toBe(['invite' => true, 'manageMembers' => true, 'transferOwnership' => false])
        ->and($asAdmin['members'][0]['isSelf'])->toBeTrue()
        ->and($asAdmin['members'][0]['hasActions'])->toBeFalse()
        ->and($asAdmin['members'][1]['hasActions'])->toBeTrue()
        ->and($asAdmin['members'][1]['links']['remove'])->toBe('/member.remove/'.$this->anaMembership->id)
        ->and($asAdmin['members'][3]['links']['remove'])->toBeNull();

    // A member who does not manage sees the list and no actions, and cannot invite.
    $asPresenter = members_index($this->ana, $this->workspace);

    expect(array_filter($actions($asPresenter)))->toBe([])
        ->and($asPresenter['metadata']['abilities'])->toBe(['invite' => false, 'manageMembers' => false, 'transferOwnership' => false])
        ->and($asPresenter['metadata']['links']['invite'])->toBeNull()
        ->and(action_keys(members_index($this->ana, $this->workspace, ['tab' => 'invitations'])['invitations'][0]))->toBe([]);
});

it('swaps suspend for reinstate, shows removed members only when asked, and confirms the destructive actions', function () {
    SuspendMembership::run($this->owner, $this->boMembership);
    RemoveMembership::run($this->owner, $this->anaMembership);

    $props = members_index($this->owner, $this->workspace);
    $bo = collect($props['members'])->firstWhere('user.name', 'Bo');

    expect(member_names($props))->not->toContain('Ana')
        ->and($bo['status'])->toBe('suspended')
        ->and(action_keys($bo))->toBe(['change-role', 'reinstate', 'remove'])
        ->and(array_column(members_index($this->owner, $this->workspace)['tabs'], 'count')[0])->toBe(4)
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['status' => 'removed']])))->toBe(['Ana'])
        ->and(action_keys(members_index($this->owner, $this->workspace, ['filter' => ['status' => 'removed']])['members'][0]))->toBe([])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['status' => 'suspended']])))->toBe(['Bo']);

    $remove = collect($bo['actions'])->firstWhere('key', 'remove');

    expect($remove)->toMatchArray(['method' => 'delete', 'destructive' => true])
        ->and($remove['confirm'])->toBe(['title' => 'Remove Bo?', 'body' => 'They lose access to this workspace straight away. You can invite them again later.', 'confirmLabel' => 'Remove member'])
        ->and(collect(collect(members_index($this->owner, $this->workspace)['members'])->firstWhere('user.name', 'Second-admin')['actions'])->firstWhere('key', 'transfer-ownership')['confirm']['title'])->toBe('Transfer ownership to Second-admin?')
        ->and(collect($bo['actions'])->firstWhere('key', 'change-role')['destructive'])->toBeFalse();
});

it('decides the empty states on the server and reports what the query was', function () {
    $other = host_workspace('Quiet', $solo = host_user('solo'));

    $first = members_index($solo, $other, ['tab' => 'invitations']);

    expect($first['table']['emptyState'])->toBe('first-use')
        ->and($first['invitations'])->toBe([])
        ->and($first['table']['pagination'])->toBe(['page' => 1, 'perPage' => 25, 'total' => 0, 'from' => null, 'to' => null, 'lastPage' => 1]);

    $none = members_index($this->owner, $this->workspace, ['filter' => ['search' => 'zzz']]);

    expect($none['table']['emptyState'])->toBe('no-results')
        ->and($none['table']['search'])->toBe('zzz')
        ->and($none['table']['filters'])->toBe(['search' => 'zzz'])
        ->and($none['metadata']['search'])->toBe('zzz');
});

it('keeps workspaces apart: no rows or counts from another workspace', function () {
    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    join_as($other, $otherOwner, host_user('ana-too'), ['presenter']);
    issue($otherOwner, $other, 'cy-too@example.test', ['presenter']);

    $props = members_index($this->owner, $this->workspace, ['filter' => ['search' => 'an']]);

    expect(member_names($props))->toBe(['Ana'])
        ->and(array_column($props['tabs'], 'count'))->toBe([1, 0])
        ->and(array_column(members_index($this->owner, $this->workspace, ['tab' => 'invitations'])['invitations'], 'email'))->not->toContain('cy-too@example.test')
        ->and(fn () => members_index($this->owner, $other))->toThrow(AuthorizationException::class)
        ->and(members_index($otherOwner, $other)['table']['pagination']['total'])->toBe(2);
});

it('is for active members only, and leaves an action out when the host has no route for it', function () {
    $stranger = host_user('stranger');

    expect(fn () => members_index($stranger, $this->workspace))->toThrow(AuthorizationException::class);

    SuspendMembership::run($this->owner, $this->boMembership);

    expect(fn () => members_index($this->bo, $this->workspace))->toThrow(AuthorizationException::class);

    $bare = members_index($this->owner, $this->workspace, urls: false);

    expect(collect($bare['members'])->sum(fn (array $row) => count($row['actions'])))->toBe(0)
        ->and($bare['metadata']['links']['invite'])->toBeNull()
        ->and($bare['metadata']['refreshUrl'])->toBe('http://localhost/members');
});

it('reads roles in a fixed number of queries however many members there are', function () {
    foreach (range(1, 12) as $i) {
        join_as($this->workspace, $this->owner, host_user("extra{$i}"), ['presenter']);
    }

    $queries = fn () => (function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        members_index($this->owner, $this->workspace);

        return count(DB::getQueryLog());
    })();

    $many = $queries();

    ChangeMemberRoles::run($this->owner, $this->boMembership, new GrantSet(['admin']));

    expect($queries())->toBe($many);
});

it('searches members and invitations without regard to case, on every database', function () {
    join_as($this->workspace, $this->owner, host_user('viewer'), ['presenter']);
    issue($this->owner, $this->workspace, 'viewer-invite@example.test', ['presenter']);

    expect(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'VIEW']])))->toBe(['Viewer'])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'vIeWeR@EXAMPLE']])))->toBe(['Viewer'])
        ->and(array_column(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'filter' => ['search' => 'VIEWER-INVITE']])['invitations'], 'email'))->toBe(['viewer-invite@example.test'])
        ->and(array_column(members_index($this->owner, $this->workspace, ['tab' => 'invitations', 'filter' => ['search' => 'VIEW']])['tabs'], 'count'))->toBe([1, 1])
        ->and(member_names(members_index($this->owner, $this->workspace, ['filter' => ['search' => 'VIEW_']])))->toBe([]);
});

it('narrows the members and their count with the host scope, in this workspace only', function () {
    $otherOwner = host_user('other-owner');
    $elsewhere = host_user('elsewhere');
    join_as(host_workspace('Other', $otherOwner), $otherOwner, $elsewhere, ['presenter']);
    $seen = [];
    $index = function (array $query = []) use (&$seen, $elsewhere) {
        return app(MembersIndex::class)
            ->scopeMembersUsing(function (Builder $memberships, $workspace) use (&$seen, $elsewhere) {
                $seen[] = $workspace->getKey();
                // An orWhere stays inside the scope: it can't reach a member of another workspace.
                $memberships->whereNotIn('user_id', [$this->ana->getKey(), $this->bo->getKey()])->orWhere('user_id', $elsewhere->getKey());
            })
            ->props($this->owner, $this->workspace, Request::create('/members', 'GET', $query))->toArray();
    };

    $props = $index();
    expect(member_names($props))->toBe(['Admin', 'Owner', 'Second-admin'])
        ->and(array_column($props['tabs'], 'count'))->toBe([3, 2])
        ->and($props['table']['pagination']['total'])->toBe(3)
        ->and(array_unique($seen))->toBe([$this->workspace->getKey()]);

    // The search narrows the scoped rows and their count together.
    $searched = $index(['filter' => ['search' => 'adm']]);
    expect(member_names($searched))->toBe(['Admin', 'Second-admin'])
        ->and(array_column($searched['tabs'], 'count'))->toBe([2, 0]);

    // Without a scope, everyone is listed as before.
    expect(member_names(members_index($this->owner, $this->workspace)))->toBe(['Admin', 'Ana', 'Bo', 'Owner', 'Second-admin']);
});
