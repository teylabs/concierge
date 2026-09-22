<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberGrants extends Data
{
    public function __construct(
        public MemberGrantsPayload $payload,
        public MemberGrantsMetadata $metadata,
    ) {}
}
