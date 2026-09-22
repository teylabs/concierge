<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberPageLinks extends Data
{
    public function __construct(
        public string $back,
    ) {}
}
