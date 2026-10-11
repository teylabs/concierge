<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Tests\Fortify\FortifyTestCase;
use Tey\Concierge\Tests\Standalone\StandaloneTestCase;
use Tey\Concierge\Tests\TenantOff\TenantInvitationsOffTestCase;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

uses(TestCase::class)->in('ArchTest.php', 'ConciergeServiceProviderTest.php', 'Concurrency', 'Data', 'Feature');
uses(StandaloneTestCase::class, RefreshDatabase::class)->in('Standalone');
uses(FortifyTestCase::class, RefreshDatabase::class)->in('Fortify');
uses(RefreshDatabase::class)->in('Feature');
// No RefreshDatabase: on a shared PostgreSQL database whichever class migrates first decides the
// schema, and this one boots without the tenant migration. It must not touch the database.
uses(TenantInvitationsOffTestCase::class)->in('TenantOff');
uses(DatabaseMigrations::class)->in('Concurrency');

function host_user(string $name, bool $verified = true): User
{
    return User::query()->create([
        'name' => ucfirst($name),
        'email' => "{$name}@example.test",
        'email_verified_at' => $verified ? now() : null,
    ]);
}

function host_workspace(string $name = 'Acme', ?User $owner = null): Workspace
{
    SyncRoleDefinitions::run();

    $workspace = Workspace::query()->create(['name' => $name]);

    if ($owner) {
        EstablishWorkspace::run($workspace, $owner);
    }

    return $workspace;
}

function issue(Member $actor, Workspace $workspace, string $email, array $roles = ['presenter']): IssuedInvitation
{
    return InviteMember::run($actor, $workspace, $email, new GrantSet($roles));
}

function join_as(Workspace $workspace, User $owner, User $user, array $roles = ['presenter']): Membership
{
    return AcceptInvitation::run($user, issue($owner, $workspace, $user->email, $roles)->token)->membership;
}

function roles_in(Membership $membership): array
{
    return app(Concierge::class)->roleNames($membership->fresh());
}

function confirm_recent_authentication(bool $confirmed = true): void
{
    app()->instance(RecentAuthentication::class, new class($confirmed) implements RecentAuthentication
    {
        public function __construct(private bool $confirmed) {}

        public function confirmed(Member $user): bool
        {
            return $this->confirmed;
        }
    });
}
