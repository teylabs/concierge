<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Data\UI\MemberGrants;
use Tey\Concierge\Data\UI\MemberGrantsMetadata;
use Tey\Concierge\Data\UI\MemberGrantsPayload;
use Tey\Concierge\Data\UI\MemberPageLinks;
use Tey\Concierge\Data\UI\MemberPageMetadata;
use Tey\Concierge\Data\UI\MemberPageProps;
use Tey\Concierge\Data\UI\WorkspaceSummary;
use Tey\Concierge\Models\Membership;

/**
 * One member's page in workspace mode: the member's row as the index shows it
 * (with only the actions the grant rules allow), and the roles form when the
 * viewer may change them. URLs come from urlsUsing(), with the same actions
 * as MembersIndex.
 */
final class MemberPage
{
    /** @var (Closure(string, ?Model): ?string)|null */
    private ?Closure $urls = null;

    public function __construct(protected MembersIndex $index, protected GrantPolicy $policy) {}

    /** @param  Closure(string, ?Model): ?string  $urls */
    public function urlsUsing(Closure $urls): static
    {
        $this->urls = $urls;
        $this->index->urlsUsing($urls);

        return $this;
    }

    public function props(Member $actor, Membership $membership): MemberPageProps
    {
        $workspace = $membership->workspace;
        $row = $this->index->row($actor, $membership);
        $grants = null;

        if ($row->links->updateGrants !== null) {
            $grants = new MemberGrants(
                new MemberGrantsPayload(array_map(fn ($badge) => $badge->value, $row->roles)),
                new MemberGrantsMetadata($row->links->updateGrants, RoleOptions::for($this->policy->grantableRoles($actor, $workspace))),
            );
        }

        return new MemberPageProps(
            workspace: new WorkspaceSummary((string) $workspace->getKey(), $workspace->conciergeName()),
            member: $row,
            grants: $grants,
            metadata: new MemberPageMetadata(new MemberPageLinks($this->url('index') ?? '/')),
        );
    }

    private function url(string $action, ?Model $subject = null): ?string
    {
        return $this->urls === null ? null : ($this->urls)($action, $subject);
    }
}
