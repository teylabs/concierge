<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Support\Config;

/**
 * Workspace and membership reads remembered for one request (or one queued
 * job, or one Octane request). It is a scoped binding, so the container drops
 * it between requests; it never touches a cache store.
 *
 * Any write to a table these reads come from, and any rolled-back
 * transaction, forgets everything, so a read after a membership, ownership or
 * role change in the same request is always fresh.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    /** Forget everything on a write to a watched table or a rollback. */
    public static function listen(Container $app): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($app) {
            if ($app->resolved(self::class)) {
                $app->make(self::class)->handleQuery($event);
            }
        });
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use ($app) {
            if ($app->resolved(self::class)) {
                $app->make(self::class)->handleRollback($event);
            }
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function remember(string $key, Closure $read): mixed
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        return $this->values[$key] = $read();
    }

    public function forget(string $prefix = ''): void
    {
        if ($prefix === '') {
            $this->values = [];

            return;
        }

        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function handleQuery(QueryExecuted $event): void
    {
        if ($this->values === [] || ! $this->writes($event->sql)) {
            return;
        }

        $sql = strtolower($event->sql);

        foreach ($this->tables() as $table) {
            if (str_contains($sql, $table)) {
                $this->forget();

                return;
            }
        }
    }

    public function handleRollback(TransactionRolledBack $event): void
    {
        $this->forget();
    }

    private function writes(string $sql): bool
    {
        $verb = strtolower(strtok(ltrim($sql), " \n\t(") ?: '');

        return $verb !== 'select';
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->tables ??= array_values(array_unique(array_map(strtolower(...), [
            (new (ConciergeModels::membership()))->getTable(),
            (new (ConciergeModels::ownership()))->getTable(),
            (new (ConciergeModels::membershipScope()))->getTable(),
            (new (ConciergeModels::workspace()))->getTable(),
            ...array_map(fn (string $user) => (new $user)->getTable(), array_filter([
                config('concierge.models.user'),
                config('auth.providers.users.model'),
            ], is_string(...))),
            Config::modelHasRolesTable(),
            Config::rolesTable(),
        ])));
    }
}
