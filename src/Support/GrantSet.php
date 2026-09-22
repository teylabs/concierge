<?php

namespace Tey\Concierge\Support;

final readonly class GrantSet
{
    /** @var list<string> */
    public array $roles;

    /** @param  iterable<string>  $roles */
    public function __construct(iterable $roles)
    {
        $roles = array_values(array_unique(array_map('strval', [...$roles])));
        sort($roles);

        $this->roles = $roles;
    }
}
