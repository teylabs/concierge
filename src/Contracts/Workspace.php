<?php

namespace Tey\Concierge\Contracts;

/**
 * Implemented by the host's Eloquent workspace model.
 */
interface Workspace
{
    public function getKey();

    public function conciergeName(): string;

    public function conciergeAcceptsMembers(): bool;
}
