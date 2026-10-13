<?php

namespace Tey\Concierge\Enums;

use Tey\Concierge\Exceptions\UnsupportedConfiguration;

/**
 * Where invitations and roles apply: the whole app, or one workspace at a time.
 * Read from the literal concierge.mode key, never from the environment, because
 * it describes the schema and every environment shares one.
 */
enum Mode: string
{
    case App = 'app';
    case Workspaces = 'workspaces';

    /**
     * A missing (or null) key means app-wide, except for the transition rule (removed at 1.0):
     * a host that predates the key and names a workspace model is in workspace mode.
     */
    public static function configured(): self
    {
        $value = config('concierge.mode');

        if ($value === null) {
            return self::inferred() ? self::Workspaces : self::App;
        }

        return (is_string($value) ? self::tryFrom($value) : null) ?? throw new UnsupportedConfiguration(sprintf(
            'concierge.mode must be one of [%s]; got %s. Set \'mode\' in config/concierge.php to one of them.',
            implode(', ', array_column(self::cases(), 'value')),
            is_string($value) ? "[{$value}]" : get_debug_type($value),
        ));
    }

    /**
     * Whether the mode comes from the transition rule rather than the key.
     * Deprecated with the rule itself, which is removed at 1.0.
     */
    public static function inferred(): bool
    {
        return config('concierge.mode') === null && config('concierge.models.workspace') !== null;
    }
}
