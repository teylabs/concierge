<?php

use Illuminate\Support\Facades\DB;

/**
 * Holds a row lock on a separate connection, forks one process per operation
 * (each on its own fresh connection, all queued behind that lock), then releases
 * the lock so they race. Returns each operation's outcome.
 *
 * @param  array<string, Closure(): string>  $operations
 * @param  string  $table  the table whose row (by id) is held; the workspace by default
 * @return array<string, string>
 */
function race(int $workspaceId, array $operations, string $table = 'workspaces'): array
{
    config()->set('database.connections.locker', config('database.connections.pgsql'));
    $locker = DB::connection('locker');
    $dir = sys_get_temp_dir().'/concierge-race-'.getmypid().'-'.uniqid();
    mkdir($dir);
    $children = [];

    try {
        $locker->beginTransaction();
        $locker->select("select id from {$table} where id = ? for update", [$workspaceId]);

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
