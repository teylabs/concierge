<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberPageMetadata extends Data
{
    public function __construct(
        public MemberPageLinks $links,
    ) {}
}
