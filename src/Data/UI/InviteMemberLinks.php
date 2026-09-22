<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InviteMemberLinks extends Data
{
    public function __construct(
        public string $cancel,
    ) {}
}
