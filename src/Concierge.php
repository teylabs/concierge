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
use Tey\Concierge\Data\UI\UserAvatar;
use Tey\Concierge\Data\UI\UserColumn;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\AppScope;
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

    /** @var (Closure(Member): ?string)|null */
    protected ?Closure $avatarUrl = null;

    /** @var array<string, string> */
    protected array $userColumnLabels = [];

    /** @var (Closure(Member): array<string, string|int|float|bool|null>)|null */
    protected ?Closure $userColumnValues = null;

    /** The configured mode: App (roles apply to the whole app) or Workspaces. */
    public function mode(): Mode
    {
        return Mode::configured();
    }

    public function context(): WorkspaceContext
    {
        return app(WorkspaceContext::class);
    }

    /**
     * The user's per-account state, in both modes. Unsaved and active when
     * the user has no row yet.
     */
    public function account(Member $user): Account
    {
        return app(AppScope::class)->account($user);
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

    /**
     * Workspace mode: whether the membership holds its workspace's ownership
     * row. App-wide mode: whether the user holds the owner role.
     */
    public function isOwner(Membership|Member $subject): bool
    {
        if ($subject instanceof Member) {
            ConciergeModels::assertAppWide('isOwner() takes a Membership in workspace mode');

            return app(AppScope::class)->isOwner($subject);
        }

        return ConciergeModels::ownership()::query()
            ->whereKey($subject->workspace_id)
            ->where('membership_id', $subject->getKey())
            ->exists();
    }

    /**
     * Workspace mode: the roles a membership holds in its workspace. App-wide
     * mode: the roles a user holds in the app.
     *
     * @return list<string>
     */
    public function roleNames(Membership|Member $subject): array
    {
        if ($subject instanceof Member) {
            ConciergeModels::assertAppWide('roleNames() takes a Membership in workspace mode');

            return app(AppScope::class)->roleNames($subject);
        }

        $membership = $subject;
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

    /**
     * Where the host keeps a user's photo, for the avatar on the people pages.
     * Return null for a user without one and the initials are shown instead.
     * Concierge never asks an outside service such as Gravatar.
     *
     * @param  (Closure(Member): ?string)|null  $callback
     */
    public function avatarUsing(?Closure $callback): static
    {
        $this->avatarUrl = $callback;

        return $this;
    }

    public function avatar(Member $user): UserAvatar
    {
        $url = $this->avatarUrl ? ($this->avatarUrl)($user) : null;

        return UserAvatar::of($user->getKey(), $user->conciergeDisplayName(), $user->conciergeEmail(), $url);
    }

    /**
     * Adds the app's own fields to each row of the Users page. The labels name
     * the columns, keyed by field; the callback returns the values for a user,
     * keyed the same way. Keys it leaves out render empty, and keys it adds
     * beyond the labels are not sent.
     *
     * @param  array<string, string>  $labels
     * @param  Closure(Member): array<string, string|int|float|bool|null>  $values
     */
    public function userColumns(array $labels, Closure $values): static
    {
        $this->userColumnLabels = $labels;
        $this->userColumnValues = $values;

        return $this;
    }

    /** @return list<UserColumn> */
    public function userColumnDefinitions(): array
    {
        return array_map(
            static fn (string $key, string $label) => new UserColumn($key, $label),
            array_keys($this->userColumnLabels),
            array_values($this->userColumnLabels),
        );
    }

    /**
     * The values of the app's columns for one user, for UserRow::$columns.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function userColumnValues(Member $user): array
    {
        if ($this->userColumnValues === null) {
            return [];
        }

        $values = ($this->userColumnValues)($user);

        return array_map(
            static fn (string $key) => $values[$key] ?? null,
            array_combine(array_keys($this->userColumnLabels), array_keys($this->userColumnLabels)),
        );
    }
}
