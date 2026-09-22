<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberLinks extends Data
{
    public function __construct(
        public ?string $show,
        public ?string $updateGrants,
        public ?string $suspend,
        public ?string $reinstate,
        public ?string $remove,
        public ?string $transferOwnership,
    ) {}
}
