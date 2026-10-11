<?php

namespace Tey\Concierge\Concerns;

/**
 * The static shortcut for an action: resolve it from the container (so its
 * dependencies are injected) and call its handle() method.
 */
trait Runnable
{
    public static function run(mixed ...$arguments): mixed
    {
        return app(static::class)->handle(...$arguments);
    }
}
