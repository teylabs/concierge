<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MembersMetadata extends Data
{
    public function __construct(
        public string $refreshUrl,
        public string $search,
        public ?string $status,
        public MembersLinks $links,
        public MembersAbilities $abilities,
    ) {}
}
