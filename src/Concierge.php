<?php

namespace Tey\Concierge;

use Closure;
use Illuminate\Auth\Access\Response;
use SensitiveParameter;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;

class Concierge
{
    /** @var (Closure(Invitation, string): string)|null */
    protected ?Closure $invitationUrl = null;

    public function context(): WorkspaceContext
    {
        return app(WorkspaceContext::class);
    }

    public function membership(Member $user, Workspace $workspace): ?Membership
    {
        return ConciergeModels::membership()::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function requireActiveMembership(Member $user, Workspace $workspace): Membership
    {
        $membership = $this->membership($user, $workspace);

        if (! $membership?->isActive()) {
            Response::deny('You are not an active member of this workspace.', 'not_member')->authorize();
        }

        return $membership;
    }

    public function ownerOf(Workspace $workspace): ?Membership
    {
        $ownership = ConciergeModels::ownership()::query()->find($workspace->getKey());

        return $ownership ? ConciergeModels::membership()::query()->find($ownership->membership_id) : null;
    }

    public function isOwner(Membership $membership): bool
    {
        return ConciergeModels::ownership()::query()
            ->whereKey($membership->workspace_id)
            ->where('membership_id', $membership->getKey())
            ->exists();
    }

    /** @return list<string> */
    public function roleNames(Membership $membership): array
    {
        $user = $membership->user;

        return $this->context()->run(
            $membership->workspace,
            fn () => Roles::sort($user->roles()->pluck('name')->all()),
            [$user],
        );
    }

    /** @param  Closure(Invitation, string): string  $callback */
    public function invitationUrlUsing(?Closure $callback): static
    {
        $this->invitationUrl = $callback;

        return $this;
    }

    public function invitationUrl(Invitation $invitation, #[SensitiveParameter] string $token): ?string
    {
        return $this->invitationUrl ? ($this->invitationUrl)($invitation, $token) : null;
    }

    public function deliversInvitations(): bool
    {
        return $this->invitationUrl !== null && config('concierge.invitations.deliver', true);
    }
}
