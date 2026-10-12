<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserRow extends Props
{
    public function __construct(
        public string $id,
        public UserSummary $user,
        #[LiteralTypeScriptType("'active' | 'deactivated'")]
        public string $status,
        public bool $isOwner,
        public bool $isSelf,
        /** A host-shielded account, such as a platform operator, that no manager may act on. */
        public bool $isOperator,
        /** @var list<RoleBadge> */
        public array $roles,
        public ?string $joinedAt,
        public bool $hasActions,
        public UserLinks $links,
        /** Whether the force-reset dialog may offer to clear two-factor: the user table has the columns. */
        public bool $twoFactorResettable = false,
        /**
         * The app's own fields from Concierge::userColumns(), keyed by column.
         *
         * @var array<string, string|int|float|bool|null>
         */
        #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
        public array $columns = [],
    ) {}
}
