<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Actions\InviteUser;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Tests\AppMode\AppModeTestCase;
use Tey\Concierge\Tests\Fortify\FortifyAppWideTestCase;
use Tey\Concierge\Tests\Fortify\FortifyTestCase;
use Tey\Concierge\Tests\Import\ImportTestCase;
use Tey\Concierge\Tests\Kit\KitTestCase;
use Tey\Concierge\Tests\Scaffolding\ModulesScaffoldTestCase;
use Tey\Concierge\Tests\Scaffolding\ReactScaffoldTestCase;
use Tey\Concierge\Tests\Scaffolding\ScaffoldTestCase;
use Tey\Concierge\Tests\Setup\ExistingInstallTestCase;
use Tey\Concierge\Tests\Setup\NotSetUpTestCase;
use Tey\Concierge\Tests\Standalone\StandaloneTestCase;
use Tey\Concierge\Tests\TenantOff\TenantInvitationsOffTestCase;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

uses(TestCase::class)->in('ArchTest.php', 'ConciergeServiceProviderTest.php', 'Concurrency', 'Data', 'Feature');
uses(ScaffoldTestCase::class)->in('Scaffolding/ConciergePagesScaffoldTest.php');
uses(ReactScaffoldTestCase::class)->in('Scaffolding/ReactPagesScaffoldTest.php');
uses(ModulesScaffoldTestCase::class)->in('Scaffolding/ModulesPagesScaffoldTest.php');
uses(StandaloneTestCase::class, RefreshDatabase::class)->in('Standalone');
uses(FortifyTestCase::class, RefreshDatabase::class)->in('Fortify/FortifyDeactivationTest.php', 'Fortify/FortifyRegistrationTest.php', 'Fortify/RememberedInvitationTest.php', 'Fortify/SwitchAccountTest.php');
// The app-wide Fortify leg has its own schema, so DatabaseMigrations (see AppModeTestCase).
uses(FortifyAppWideTestCase::class, DatabaseMigrations::class)->in('Fortify/AppWide');
uses(KitTestCase::class, RefreshDatabase::class)->in('Kit');
uses(ImportTestCase::class, RefreshDatabase::class)->in('Import');
uses(RefreshDatabase::class)->in('Feature');
// No RefreshDatabase: on a shared PostgreSQL database whichever class migrates first decides the
// schema, and this one boots without the tenant migration. It must not touch the database.
uses(TenantInvitationsOffTestCase::class)->in('TenantOff');
uses(NotSetUpTestCase::class)->in('Setup/NotSetUpTest.php');
uses(ExistingInstallTestCase::class, RefreshDatabase::class)->in('Setup/ExistingInstallTest.php');
uses(TestCase::class)->in('Setup/SetupSwitchTest.php');
uses(DatabaseMigrations::class)->in('Concurrency');
// The app-wide leg: its own schema, so DatabaseMigrations (see AppModeTestCase).
uses(AppModeTestCase::class, DatabaseMigrations::class)->in('AppMode');

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

// App-wide mode (tests/AppMode).

function app_owner(string $name = 'owner'): User
{
    SyncRoleDefinitions::run();

    return tap(host_user($name), fn (User $user) => EstablishOwner::run($user));
}

function invite_user(Member $actor, string $email, array $roles = ['presenter']): IssuedInvitation
{
    return InviteUser::run($actor, $email, new GrantSet($roles));
}

/** Invite first, then the person registers and accepts: the roster is the users table. */
function join_app(User $actor, string $name, array $roles = ['presenter']): User
{
    $issued = invite_user($actor, "{$name}@example.test", $roles);
    $user = host_user($name);

    AcceptInvitation::run($user, $issued->token);

    return $user;
}

function app_roles(User $user): array
{
    return app(Concierge::class)->roleNames($user->fresh());
}
