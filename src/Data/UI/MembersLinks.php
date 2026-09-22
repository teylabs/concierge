<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MembersLinks extends Data
{
    public function __construct(
        public ?string $invite,
    ) {}
}
