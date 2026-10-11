<?php

namespace Tey\Concierge\Exceptions;

use LogicException;
use Tey\Concierge\Contracts\Workspace;

/**
 * A write was asked of a workspace whose members concierge only reads, such
 * as a starter kit's team.
 */
class WorkspaceNotManaged extends LogicException
{
    public static function kitTeam(Workspace $workspace): self
    {
        return new self(sprintf(
            "Workspace [%s] (%s) is a starter kit team: concierge reads its members and roles but doesn't change them. "
            ."Change it with the kit's own team screens, or let concierge take the kit's teams over once its scaffolding lands (teylabs/concierge#57).",
            $workspace->getKey(),
            $workspace->conciergeName(),
        ));
    }
}
