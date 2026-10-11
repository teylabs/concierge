<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UsersPageProps extends Props
{
    public function __construct(
        /** @var list<UserRow> */
        public array $users,
        public Pagination $pagination,
        public UsersMetadata $metadata,
    ) {}
}
