<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/**
 * The members index as a server-driven table, in the shape a generic data table takes: the rows already
 * sorted, filtered and paginated for the active tab, the columns in display order, and the state of the
 * query (sort, search, filters). Concierge builds it from the URL (`?tab`, `?sort`, `?filter[search]`,
 * `?filter[role]`, `?page`, `?per_page`); a UI only renders it.
 */
#[TypeScript]
readonly class MembersTable extends Props
{
    public function __construct(
        /** @var list<MemberRow|InvitationRow> */
        public array $rows,
        /** @var list<TableColumn> */
        public array $columns,
        public ?TableSort $sort,
        public ?string $search,
        /** @var array<string, string> */
        public array $filters,
        /** @var list<MembersTab> */
        public array $tabs,
        public TablePagination $pagination,
        #[LiteralTypeScriptType("'first-use' | 'no-results' | null")]
        public ?string $emptyState,
    ) {}
}
