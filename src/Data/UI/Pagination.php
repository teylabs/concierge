<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class Pagination extends Data
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public ?string $previousUrl,
        public ?string $nextUrl,
    ) {}
}
