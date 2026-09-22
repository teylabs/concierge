<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class RoleOption extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description,
    ) {}
}
