<?php

use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Tests\TestCase;

beforeEach(function () {
    if (! TestCase::usesPostgres() || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Concurrency claims require PostgreSQL (CONCIERGE_TEST_DB=pgsql) and pcntl.');
    }

    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    confirm_recent_authentication();
});

/**
 * Holds the workspace row lock on a separate connection, forks one process per
 * operation (each on its own fresh connection, all queued behind that lock),
 * then releases the lock so they race. Returns each operation's outcome.
 *
 * @param  array<string, Closure(): string>  $operations
 * @return array<string, string>
 */
function race(int $workspaceId, array $operations): array
{
    config()->set('database.connections.locker', config('database.connections.pgsql'));
    $locker = DB::connection('locker');
    $dir = sys_get_temp_dir().'/concierge-race-'.getmypid().'-'.uniqid();
    mkdir($dir);
    $children = [];

    try {
        $locker->beginTransaction();
        $locker->select('select id from workspaces where id = ? for update', [$workspaceId]);

        foreach ($operations as $name => $operation) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $inherited = [DB::connection(), $locker];
                DB::purge('pgsql');
                try {
                    DB::statement("set lock_timeout = '10s'");
                    $result = $operation();
                } catch (Throwable $e) {
                    $result = class_basename($e).':'.($e->state->value ?? $e->getCode());
                }
                file_put_contents("{$dir}/{$name}", $result);
                posix_kill(getmypid(), SIGKILL);
            }

            $children[$name] = $pid;
        }

        $deadline = microtime(true) + 5;
        do {
            usleep(50_000);
            $waiting = (int) $locker->selectOne(
                "select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
            )->waiting;
        } while ($waiting < count($operations) && microtime(true) < $deadline);

        expect($waiting)->toBe(count($operations));

        $locker->commit();
        reap($children, 15);

        $results = [];
        foreach (array_keys($operations) as $name) {
            $results[$name] = @file_get_contents("{$dir}/{$name}") ?: 'no-result';
        }

        return $results;
    } finally {
        if ($locker->transactionLevel() > 0) {
            $locker->rollBack();
        }

        reap($children, 15);
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
        DB::purge('locker');
    }
}

/** @param  array<string, int>  $children */
function reap(array &$children, int $seconds): void
{
    $deadline = microtime(true) + $seconds;

    while ($children !== [] && microtime(true) < $deadline) {
        foreach ($children as $name => $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset($children[$name]);
            }
        }
        usleep(20_000);
    }

    foreach ($children as $name => $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        unset($children[$name]);
    }
}

it('accepts one invitation exactly once under concurrent retries', function () {
    $alice = host_user('alice');
    $token = issue($this->owner, $this->workspace, $alice->email)->token;

    $results = race($this->workspace->id, [
        'first' => fn () => AcceptInvitation::run($alice, $token)->outcome->value,
        'second' => fn () => AcceptInvitation::run($alice, $token)->outcome->value,
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['already_member', 'joined'])
        ->and(DB::table('concierge_memberships')->where('user_id', $alice->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('model_id', $alice->id)->count())->toBe(1);
});

it('serializes acceptance against revocation', function () {
    $alice = host_user('alice');
    $issued = issue($this->owner, $this->workspace, $alice->email);

    $results = race($this->workspace->id, [
        'accept' => fn () => AcceptInvitation::run($alice, $issued->token)->outcome->value,
        'revoke' => fn () => RevokeInvitation::run($this->owner, $issued->invitation)->status->value,
    ]);

    $invitation = $issued->invitation->fresh();
    $membership = $alice->membershipIn($this->workspace);

    if ($results['accept'] === 'joined') {
        expect($results['revoke'])->toBe('ValidationException:0')
            ->and($invitation->status)->toBe(InvitationStatus::Accepted)
            ->and($membership->status)->toBe(MembershipStatus::Active);
    } else {
        expect($results)->toBe(['accept' => 'InvitationRejected:revoked', 'revoke' => 'revoked'])
            ->and($invitation->status)->toBe(InvitationStatus::Revoked)
            ->and($membership)->toBeNull();
    }
});

it('never produces two owners from racing transfers', function () {
    $x = join_as($this->workspace, $this->owner, host_user('x'), ['admin']);
    $y = join_as($this->workspace, $this->owner, host_user('y'), ['admin']);

    $results = race($this->workspace->id, [
        'x' => fn () => TransferOwnership::run($this->owner, $x)->id,
        'y' => fn () => TransferOwnership::run($this->owner, $y)->id,
    ]);

    expect(collect($results)->filter(fn ($r) => $r === 'AuthorizationException:not_owner'))->toHaveCount(1)
        ->and(DB::table('concierge_ownerships')->where('workspace_id', $this->workspace->id)->count())->toBe(1);

    $owners = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.workspace_id', $this->workspace->id)
        ->where('roles.name', 'owner')
        ->pluck('model_id')->all();

    expect($owners)->toBe([app(Concierge::class)->ownerOf($this->workspace)->user_id]);
});

it('never suspends the owner when a transfer races a suspension', function () {
    $admin = host_user('admin');
    join_as($this->workspace, $this->owner, $admin, ['admin']);
    $recipient = join_as($this->workspace, $this->owner, host_user('recipient'), ['presenter']);

    $results = race($this->workspace->id, [
        'transfer' => fn () => (string) TransferOwnership::run($this->owner, $recipient)->id,
        'suspend' => fn () => SuspendMembership::run($admin, $recipient)->status->value,
    ]);

    $owner = app(Concierge::class)->ownerOf($this->workspace);

    expect($owner->status)->toBe(MembershipStatus::Active)
        ->and(in_array($results, [
            ['transfer' => (string) $recipient->id, 'suspend' => 'AuthorizationException:owner_protected'],
            ['transfer' => 'AuthorizationException:recipient_invalid', 'suspend' => 'suspended'],
        ], true))->toBeTrue();
});
