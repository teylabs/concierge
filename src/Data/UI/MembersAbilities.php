<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MembersAbilities extends Data
{
    public function __construct(
        public bool $invite,
        public bool $manageMembers,
        public bool $transferOwnership,
    ) {}
}
