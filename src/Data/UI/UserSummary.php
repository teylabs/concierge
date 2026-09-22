<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class UserSummary extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}
}
