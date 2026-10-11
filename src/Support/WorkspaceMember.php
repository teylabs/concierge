<?php

namespace Tey\Concierge\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\Workspace;

/**
 * One person's place in one workspace, as a MembershipSource reads it.
 */
final readonly class WorkspaceMember
{
    /** @param  list<string>  $roles */
    public function __construct(
        public Workspace&Model $workspace,
        public int $userId,
        public array $roles,
        public bool $owner,
        public ?CarbonInterface $joinedAt,
    ) {}
}
