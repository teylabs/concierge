<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberGrantsPayload extends Data
{
    public function __construct(
        /** @var list<string> */
        public array $roles,
    ) {}
}
