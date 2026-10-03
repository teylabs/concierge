<?php

namespace Tey\Concierge;

use Closure;
use Illuminate\Auth\Access\Response;
use SensitiveParameter;
use Tey\Concierge\Contracts\BatchesMembershipReads;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\MembershipDirectory;
use Tey\Concierge\Support\Roles;

class Concierge
{
    /** @var (Closure(Invitation, string): string)|null */
    protected ?Closure $invitationUrl = null;

    /** @var (Closure(TenantInvitation, string): string)|null */
    protected ?Closure $tenantInvitationUrl = null;

    /** @var (Closure(Member, TenantInvitation): mixed)|null */
    protected ?Closure $workspaceProvisioner = null;

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

    /**
     * A fresh read snapshot for rendering one page; never cached here.
     *
     * @param  iterable<Membership>  $memberships
     */
    public function directory(Workspace $workspace, Member $actor, iterable $memberships = []): MembershipDirectory
    {
        return MembershipDirectory::load($this, $workspace, $actor, $memberships);
    }

    /**
     * The bound grant policy, batched through the directory when it opts in;
     * custom policies that do not opt in are returned untouched.
     */
    public function policyFor(MembershipDirectory $directory): GrantPolicy
    {
        /** @var GrantPolicy $policy hosts may rebind this to a custom policy */
        $policy = app(GrantPolicy::class);

        return $policy instanceof BatchesMembershipReads ? $policy->usingDirectory($directory) : $policy;
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

    /** @param  Closure(TenantInvitation, string): string  $callback */
    public function tenantInvitationUrlUsing(?Closure $callback): static
    {
        $this->tenantInvitationUrl = $callback;

        return $this;
    }

    public function tenantInvitationUrl(TenantInvitation $invitation, #[SensitiveParameter] string $token): ?string
    {
        return $this->tenantInvitationUrl ? ($this->tenantInvitationUrl)($invitation, $token) : null;
    }

    public function deliversTenantInvitations(): bool
    {
        return $this->tenantInvitationUrl !== null && config('concierge.invitations.deliver', true);
    }

    /**
     * How the host creates the (saved) workspace an accepted tenant invitation
     * provisions. Runs inside the acceptance transaction; the invitee is then
     * established as its owner.
     *
     * @param  (Closure(Member, TenantInvitation): mixed)|null  $callback
     */
    public function provisionWorkspaceUsing(?Closure $callback): static
    {
        $this->workspaceProvisioner = $callback;

        return $this;
    }

    /** @return (Closure(Member, TenantInvitation): mixed)|null */
    public function workspaceProvisioner(): ?Closure
    {
        return $this->workspaceProvisioner;
    }
}
