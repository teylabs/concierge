<?php

namespace Tey\Concierge\Enums;

use Tey\Concierge\Exceptions\UnsupportedConfiguration;

/**
 * Who keeps a workspace's members in workspace mode: concierge's own tables, or
 * a starter kit's teams, which concierge reads and never writes. Read from the
 * literal concierge.workspaces.engine key, like the mode.
 */
enum WorkspaceEngine: string
{
    case Concierge = 'concierge';
    case Kit = 'kit';

    /** A missing (or null) key means concierge's own engine. */
    public static function configured(): self
    {
        $value = config('concierge.workspaces.engine');

        if ($value === null) {
            return self::Concierge;
        }

        return (is_string($value) ? self::tryFrom($value) : null) ?? throw new UnsupportedConfiguration(sprintf(
            'concierge.workspaces.engine must be one of [%s]; got %s.',
            implode(', ', array_column(self::cases(), 'value')),
            is_string($value) ? "[{$value}]" : get_debug_type($value),
        ));
    }
}
