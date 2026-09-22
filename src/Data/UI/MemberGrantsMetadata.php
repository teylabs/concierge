<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberGrantsMetadata extends Data
{
    public function __construct(
        public string $formUrl,
        /** @var list<RoleOption> */
        public array $roleOptions,
    ) {}
}
