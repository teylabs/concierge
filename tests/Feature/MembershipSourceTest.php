<?php

use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeMembershipSource;
use Tey\Concierge\Support\PermissionWorkspaceContext;
use Tey\Concierge\Support\WorkspaceMember;
use Workbench\App\Models\User;

/** @param  list<WorkspaceMember>  $members */
function described_members(array $members): array
{
    return array_map(fn (WorkspaceMember $m) => [User::query()->find($m->userId)->name, $m->roles, $m->owner], $members);
}

it("binds concierge's own source and context on the concierge engine", function () {
    expect(app(MembershipSource::class))->toBeInstanceOf(ConciergeMembershipSource::class)
        ->and(app(WorkspaceContext::class))->toBeInstanceOf(PermissionWorkspaceContext::class)
        ->and(app(MembershipSource::class)->managesMembers(host_workspace()))->toBeTrue();
});

it("reads active members and their workspace-scoped roles from concierge's tables", function () {
    $owner = host_user('owner');
    $acme = host_workspace('Acme', $owner);
    $this->travel(1)->seconds();
    $adam = host_user('adam');
    join_as($acme, $owner, $adam, ['admin']);
    $this->travel(1)->seconds();
    $sam = host_user('sam');
    join_as($acme, $owner, $sam);

    $other = host_workspace('Other', $adam);
    join_as($other, $adam, $owner, ['presenter']);

    SuspendMembership::run($owner, $sam->membershipIn($acme));

    $source = app(MembershipSource::class);

    expect(described_members($source->members($acme)))->toBe([
        ['Owner', ['owner'], true],
        ['Adam', ['admin'], false],
    ])->and($source->member($acme, $sam))->toBeNull()
        ->and($source->member($acme, $adam)?->roles)->toBe(['admin'])
        ->and(array_map(fn (WorkspaceMember $m) => [$m->workspace->conciergeName(), $m->roles, $m->owner], $source->workspacesOf($owner)))->toBe([
            ['Acme', ['owner'], true],
            ['Other', ['presenter'], false],
        ]);
});

it('rejects an unknown workspace engine, naming the values', function () {
    config()->set('concierge.workspaces.engine', 'jetstream');

    expect(fn () => WorkspaceEngine::configured())
        ->toThrow(UnsupportedConfiguration::class, 'concierge.workspaces.engine must be one of [concierge, kit]; got [jetstream].');
});

it('ships the concierge engine as the default', function () {
    expect((require __DIR__.'/../../config/concierge.php')['workspaces'])->toBe(['engine' => 'concierge']);
});
