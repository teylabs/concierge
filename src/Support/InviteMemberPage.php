<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Data\UI\InviteMemberLinks;
use Tey\Concierge\Data\UI\InviteMemberMetadata;
use Tey\Concierge\Data\UI\InviteMemberPageProps;
use Tey\Concierge\Data\UI\InviteMemberPayload;
use Tey\Concierge\Data\UI\WorkspaceSummary;

/**
 * The invite form in workspace mode: the roles the actor may grant, where the
 * form posts and where Cancel goes. InviteMember checks every grant again.
 */
final class InviteMemberPage
{
    public function __construct(protected GrantPolicy $policy) {}

    public function props(Member $actor, Workspace $workspace, string $formUrl, string $cancelUrl): InviteMemberPageProps
    {
        return new InviteMemberPageProps(
            workspace: new WorkspaceSummary((string) $workspace->getKey(), $workspace->conciergeName()),
            payload: new InviteMemberPayload('', []),
            metadata: new InviteMemberMetadata(
                formUrl: $formUrl,
                roleOptions: RoleOptions::for($this->policy->grantableRoles($actor, $workspace)),
                links: new InviteMemberLinks($cancelUrl),
            ),
        );
    }
}
