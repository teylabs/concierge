<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InviteMemberMetadata extends Data
{
    public function __construct(
        public string $formUrl,
        /** @var list<RoleOption> */
        public array $roleOptions,
        public InviteMemberLinks $links,
    ) {}
}
