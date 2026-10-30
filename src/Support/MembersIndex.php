<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Support\Config;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\BatchesMembershipReads;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Data\UI\InvitationLinks;
use Tey\Concierge\Data\UI\InvitationRow;
use Tey\Concierge\Data\UI\MemberLinks;
use Tey\Concierge\Data\UI\MemberRow;
use Tey\Concierge\Data\UI\MembersAbilities;
use Tey\Concierge\Data\UI\MembersLinks;
use Tey\Concierge\Data\UI\MembersMetadata;
use Tey\Concierge\Data\UI\MembersPageProps;
use Tey\Concierge\Data\UI\MembersTab;
use Tey\Concierge\Data\UI\MembersTable;
use Tey\Concierge\Data\UI\Pagination;
use Tey\Concierge\Data\UI\RoleBadge;
use Tey\Concierge\Data\UI\RowAction;
use Tey\Concierge\Data\UI\RowActionConfirm;
use Tey\Concierge\Data\UI\TableColumn;
use Tey\Concierge\Data\UI\TablePagination;
use Tey\Concierge\Data\UI\TableSort;
use Tey\Concierge\Data\UI\UserSummary;
use Tey\Concierge\Data\UI\WorkspaceSummary;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;

/**
 * The members index of one workspace, built from the request: the Members and Invitations tabs with their
 * counts, and the active tab's rows as a server-driven table. The query is spatie/laravel-query-builder over
 * `?sort`, `?filter[search]`, `?filter[role]`, `?filter[status]`, `?page` and `?per_page` (an unknown sort or
 * filter is a 400, never ignored), the tab is `?tab`, and each row carries only the actions the grant rules let
 * the viewer take: rank decides, so an admin has none on the owner. Every membership read for the page is
 * batched through a MembershipDirectory.
 *
 * Concierge owns the data and the rules; the host owns its routes, so it tells the builder where each action
 * goes with urlsUsing(). An action with no URL is left out.
 */
final class MembersIndex
{
    public const array TABS = ['members', 'invitations'];

    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /** @var (Closure(string, ?Model): ?string)|null */
    private ?Closure $urls = null;

    private bool $includeScopedGuests = false;

    /** @var (Closure(Builder, Workspace): void)|null */
    private ?Closure $memberScope = null;

    public function __construct(protected Concierge $concierge, protected GrantPolicy $policy) {}

    /**
     * @param  Closure(string, ?Model): ?string  $urls  given an action (index, invite, member.show,
     *                                                  member.updateGrants, member.suspend, member.reinstate, member.remove,
     *                                                  member.transferOwnership, invitation.resend, invitation.revoke) and its
     *                                                  member or invitation, the URL, or null when the host has no route for it
     */
    public function urlsUsing(Closure $urls): static
    {
        $this->urls = $urls;

        return $this;
    }

    /**
     * Lists scoped guests too, for a page that manages them. They are left out
     * by default, and their rows never offer a role change or an ownership
     * transfer.
     */
    /**
     * Narrows the members the index lists and counts with the host's own rule (hiding a support role, say). The
     * scope is given the memberships query, already joined to the users table, and the workspace. It applies to the
     * Members tab's rows and to its count alike, so the two always agree; the Invitations tab is untouched.
     *
     * @param  Closure(Builder, Workspace): void  $scope
     */
    public function scopeMembersUsing(Closure $scope): static
    {
        $this->memberScope = $scope;

        return $this;
    }

    public function includingScopedGuests(bool $include = true): static
    {
        $this->includeScopedGuests = $include;

        return $this;
    }

    public function props(Member $actor, Workspace $workspace, Request $request): MembersPageProps
    {
        if ($this->concierge->mode() !== Mode::Workspaces) {
            throw new UnsupportedConfiguration("The members index needs workspace mode; set 'mode' => 'workspaces' in config/concierge.php. App-wide mode lists users instead.");
        }

        $tab = is_string($raw = $request->query('tab', 'members')) ? $raw : '';

        abort_unless(in_array($tab, self::TABS, true), 400, 'Unknown tab.');

        $actorMembership = $this->concierge->membership($actor, $workspace);

        // A scoped guest reaches only their records, never the workspace's member list.
        if (! $actorMembership?->isActive() || $this->concierge->isScopedGuest($actorMembership)) {
            Response::deny(__('You are not an active member of this workspace.'), 'not_member')->authorize();
        }

        $search = is_string($raw = Arr::get((array) $request->query('filter', []), 'search')) ? trim($raw) : '';
        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $page = max((int) $request->query('page', 1), 1);

        $tabs = $this->tabs($workspace, $request, $tab, $search);

        $paginator = $tab === 'members'
            ? $this->membersQuery($workspace, $request)->paginate($perPage, ['*'], 'page', $page)
            : $this->invitationsQuery($workspace, $request)->paginate($perPage, ['*'], 'page', $page);

        $paginator->withPath($this->url('index') ?? $request->url())->appends($request->query());

        $directory = MembershipDirectory::load($this->concierge, $workspace, $actor, array_values(array_filter($paginator->items(), fn ($row) => $row instanceof Membership)));
        $policy = $this->policy instanceof BatchesMembershipReads ? $this->policy->usingDirectory($directory) : $this->policy;

        $members = [];
        $invitations = [];

        if ($tab === 'members') {
            /** @var Membership $membership */
            foreach ($paginator->getCollection() as $membership) {
                $members[] = $this->memberRow($membership, $actor, $workspace, $actorMembership, $directory, $policy);
            }
        } else {
            /** @var Invitation $invitation */
            foreach ($paginator->getCollection() as $invitation) {
                $invitations[] = $this->invitationRow($invitation, $actor, $workspace, $policy);
            }
        }

        $filters = array_map('strval', array_filter(Arr::only((array) $request->query('filter', []), ['search', 'role', 'status']), fn ($value) => is_string($value) && $value !== ''));
        $empty = $paginator->total() === 0 ? ($filters === [] ? 'first-use' : 'no-results') : null;
        $sort = $this->sort($request, $tab);

        $table = new MembersTable(
            rows: $tab === 'members' ? $members : $invitations,
            columns: $this->columns($tab),
            sort: $sort,
            search: $search === '' ? null : $search,
            filters: $filters,
            tabs: $tabs,
            pagination: new TablePagination($paginator->currentPage(), $paginator->perPage(), $paginator->total(), $paginator->firstItem(), $paginator->lastItem(), $paginator->lastPage()),
            emptyState: $empty,
        );

        $manages = $policy->authorizeManagement($actor, $workspace)->allowed();

        return new MembersPageProps(
            workspace: new WorkspaceSummary((string) $workspace->getKey(), $workspace->conciergeName()),
            members: $members,
            pagination: new Pagination($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), $paginator->previousPageUrl(), $paginator->nextPageUrl()),
            invitations: $invitations,
            metadata: new MembersMetadata(
                refreshUrl: $this->url('index') ?? $request->url(),
                search: $search,
                status: $filters['status'] ?? null,
                links: new MembersLinks($manages && $policy->grantableRoles($actor, $workspace) !== [] ? $this->url('invite') : null),
                abilities: new MembersAbilities($manages && $policy->grantableRoles($actor, $workspace) !== [], $manages, $directory->isOwner($actorMembership)),
            ),
            tab: $tab,
            tabs: $tabs,
            table: $table,
        );
    }

    private function membersQuery(Workspace $workspace, Request $request): QueryBuilder
    {
        [$users, $memberships, $userKey] = $this->tables();
        $filters = (array) $request->query('filter', []);

        $query = ConciergeModels::membership()::query()
            ->join($users, "{$users}.{$userKey}", '=', "{$memberships}.user_id")
            ->where("{$memberships}.workspace_id", $workspace->getKey())
            ->select("{$memberships}.*")
            ->with(['user', 'invitation'])
            ->when(! $this->includeScopedGuests, fn (Builder $q) => $q->where("{$memberships}.guest", false));
        $this->applyMemberScope($query, $workspace);

        // Members who have left are listed only when asked for.
        if (($filters['status'] ?? null) === null) {
            $query->where("{$memberships}.status", '!=', MembershipStatus::Removed->value);
        }

        // Search and role take one value: no delimiter, so `Viewer, Owner` stays one string instead of Spatie's comma-split array. A value that still arrives
        // as an array (`filter[search][]=x`) is invalid input and is ignored, never cast to a string.
        return QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $q, $value) => is_string($value) ? $this->searchMembers($q, $value) : $q)->delimiter(''),
                AllowedFilter::callback('role', fn (Builder $q, $value) => is_string($value) ? $this->withRole($q, $workspace, $value) : $q)->delimiter(''),
                AllowedFilter::exact('status', "{$memberships}.status"),
            )
            ->allowedSorts(AllowedSort::field('name', "{$users}.name"), AllowedSort::field('joined', "{$memberships}.joined_at"))
            ->defaultSort("{$users}.name", "{$memberships}.id");
    }

    private function invitationsQuery(Workspace $workspace, Request $request): QueryBuilder
    {
        $query = ConciergeModels::invitation()::query()
            ->with('inviter')
            ->where('workspace_id', $workspace->getKey())
            ->where('status', InvitationStatus::Pending->value);

        return QueryBuilder::for($query, $request)
            ->allowedFilters(AllowedFilter::callback('search', fn (Builder $q, $value) => is_string($value) ? $q->whereLike('email', '%'.$this->escape($value).'%', caseSensitive: false) : $q)->delimiter(''))
            ->allowedSorts(AllowedSort::field('email'), AllowedSort::field('sent', 'last_sent_at'), AllowedSort::field('expires', 'expires_at'))
            ->defaultSort('expires_at', 'id');
    }

    /**
     * Both tabs with their counts, which follow the active search and nothing else.
     *
     * @return list<MembersTab>
     */
    private function tabs(Workspace $workspace, Request $request, string $current, string $search): array
    {
        [$users, $memberships, $userKey] = $this->tables();

        $members = ConciergeModels::membership()::query()
            ->join($users, "{$users}.{$userKey}", '=', "{$memberships}.user_id")
            ->where("{$memberships}.workspace_id", $workspace->getKey())
            ->where("{$memberships}.status", '!=', MembershipStatus::Removed->value)
            ->when(! $this->includeScopedGuests, fn (Builder $q) => $q->where("{$memberships}.guest", false));
        $this->applyMemberScope($members, $workspace);

        $invitations = ConciergeModels::invitation()::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', InvitationStatus::Pending->value);

        if ($search !== '') {
            $this->searchMembers($members, $search);
            $invitations->whereLike('email', '%'.$this->escape($search).'%', caseSensitive: false);
        }

        $counts = ['members' => $members->count(), 'invitations' => $invitations->count()];
        $labels = ['members' => __('Members'), 'invitations' => __('Invitations')];

        return array_map(fn (string $key) => new MembersTab(
            $key,
            $labels[$key],
            $this->tabUrl($request, $key, $search),
            $counts[$key],
            $key === $current,
        ), self::TABS);
    }

    /** A tab keeps the search and starts again at page one with its own default sort. */
    private function tabUrl(Request $request, string $tab, string $search): string
    {
        $query = array_filter(['tab' => $tab === 'members' ? null : $tab, 'filter' => $search === '' ? null : ['search' => $search], 'per_page' => $request->query('per_page')]);
        $base = $this->url('index') ?? $request->url();

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    private function memberRow(Membership $membership, Member $actor, Workspace $workspace, Membership $actorMembership, MembershipDirectory $directory, GrantPolicy $policy): MemberRow
    {
        $user = $membership->user;
        $roles = $directory->roleNames($membership);
        $manageable = $membership->status !== MembershipStatus::Removed && $policy->authorizeManagement($actor, $workspace, $membership)->allowed();
        $actions = [];
        $link = fn (string $name, bool $allowed) => $allowed ? $this->url('member.'.$name, $membership) : null;

        $guest = $this->concierge->isScopedGuest($membership);
        $updateGrants = $link('updateGrants', $manageable && ! $guest && $policy->grantableRoles($actor, $workspace) !== []);
        $suspend = $link('suspend', $manageable && $membership->status === MembershipStatus::Active);
        $reinstate = $link('reinstate', $manageable && $membership->status === MembershipStatus::Suspended);
        $remove = $link('remove', $manageable);
        $transfer = $link('transferOwnership', $directory->isOwner($actorMembership)
            && $membership->status === MembershipStatus::Active
            && ! $guest
            && ! $directory->isOwner($membership)
            && (int) $membership->user_id !== (int) $actor->getKey());

        $name = $user->conciergeDisplayName();
        $otherEmail = $membership->invitation?->accepted_with_other_email === true
            && (int) $membership->invitation->accepted_by_id === (int) $membership->user_id;

        $updateGrants !== null && $actions[] = new RowAction('change-role', __('Change role'), $updateGrants, 'put');
        $suspend !== null && $actions[] = new RowAction('suspend', __('Suspend'), $suspend, 'post', false, new RowActionConfirm(
            __('Suspend :name?', ['name' => $name]),
            __('They lose access until reinstated. Their roles are kept.'),
            __('Suspend member'),
        ));
        $reinstate !== null && $actions[] = new RowAction('reinstate', __('Reinstate'), $reinstate, 'post', false, new RowActionConfirm(
            __('Reinstate :name?', ['name' => $name]),
            __('They get their access and roles back.'),
            __('Reinstate member'),
        ));
        $transfer !== null && $actions[] = new RowAction('transfer-ownership', __('Transfer ownership'), $transfer, 'post', true, new RowActionConfirm(
            __('Transfer ownership to :name?', ['name' => $name]),
            __('They become the owner and you become an admin.'),
            __('Transfer ownership'),
        ));
        $remove !== null && $actions[] = new RowAction('remove', __('Remove'), $remove, 'delete', true, new RowActionConfirm(
            __('Remove :name?', ['name' => $name]),
            __('They lose access to this workspace straight away. You can invite them again later.'),
            __('Remove member'),
        ));

        return new MemberRow(
            id: (string) $membership->getKey(),
            user: UserSummary::for($user),
            status: $membership->status->value,
            isOwner: $directory->isOwner($membership),
            isSelf: (int) $membership->user_id === (int) $actor->getKey(),
            roles: $this->badges($roles),
            joinedAt: $membership->joined_at?->toIso8601String(),
            hasActions: $actions !== [],
            links: new MemberLinks($this->url('member.show', $membership), $updateGrants, $suspend, $reinstate, $remove, $transfer),
            actions: $actions,
            acceptedWithOtherEmail: $otherEmail,
            invitedEmail: $otherEmail ? $membership->invitation->email : null,
        );
    }

    private function invitationRow(Invitation $invitation, Member $actor, Workspace $workspace, GrantPolicy $policy): InvitationRow
    {
        $roles = Roles::sort($invitation->roleNames());
        $manages = $policy->authorizeManagement($actor, $workspace)->allowed();
        $grant = $invitation->isScoped()
            ? ScopedGrants::authorize($policy, $actor, $workspace, new GrantSet($roles))
            : $policy->authorizeGrant($actor, $workspace, new GrantSet($roles));
        $resend = $manages && $grant->allowed() ? $this->url('invitation.resend', $invitation) : null;
        $copy = $resend !== null ? $this->url('invitation.copyLink', $invitation) : null;
        $revoke = $manages ? $this->url('invitation.revoke', $invitation) : null;
        $actions = [];

        $resend !== null && $actions[] = new RowAction('resend', __('Resend'), $resend, 'put');
        $copy !== null && $actions[] = new RowAction('copy_link', __('Copy link'), $copy, 'post', false, new RowActionConfirm(
            __('Copy a new link for :email?', ['email' => $invitation->email]),
            __('The previous link will stop working. No email is sent.'),
            __('Copy link'),
        ));
        $revoke !== null && $actions[] = new RowAction('revoke', __('Revoke'), $revoke, 'delete', true, new RowActionConfirm(
            __('Revoke the invitation to :email?', ['email' => $invitation->email]),
            __('The link stops working. You can invite them again later.'),
            __('Revoke invitation'),
        ));

        return new InvitationRow(
            id: (string) $invitation->public_id,
            email: (string) $invitation->email,
            roles: $this->badges($roles),
            status: $invitation->isExpired() ? InvitationStatus::Expired->value : $invitation->status->value,
            expiresAt: $invitation->expires_at->toIso8601String(),
            lastSentAt: $invitation->last_sent_at?->toIso8601String(),
            invitedBy: $invitation->inviter?->conciergeDisplayName(),
            hasActions: $actions !== [],
            links: new InvitationLinks($resend, $revoke),
            actions: $actions,
            message: $invitation->message,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<RoleBadge>
     */
    private function badges(array $roles): array
    {
        return array_map(fn (string $role) => new RoleBadge($role, (string) config("concierge.roles.{$role}.label", ucfirst($role)), $role === Roles::owner() ? 'primary-solid' : 'info'), $roles);
    }

    /** @return list<TableColumn> */
    private function columns(string $tab): array
    {
        if ($tab === 'members') {
            return [
                new TableColumn('member', __('Member'), sortable: true),
                new TableColumn('roles', __('Role')),
                new TableColumn('status', __('Status'), hideable: true, hiddenByDefault: true),
                new TableColumn('joined', __('Joined'), sortable: true, hideable: true),
                new TableColumn('actions', __('Actions'), align: 'end', srOnlyLabel: true),
            ];
        }

        return [
            new TableColumn('email', __('Email'), sortable: true),
            new TableColumn('roles', __('Role')),
            new TableColumn('invitedBy', __('Invited by'), hideable: true),
            new TableColumn('sent', __('Sent'), sortable: true, hideable: true),
            new TableColumn('expires', __('Expires'), sortable: true),
            new TableColumn('actions', __('Actions'), align: 'end', srOnlyLabel: true),
        ];
    }

    private function sort(Request $request, string $tab): TableSort
    {
        $requested = is_string($raw = $request->query('sort')) ? $raw : '';
        $sort = ltrim($requested, '-');
        $column = ['members' => ['name' => 'member', 'joined' => 'joined'], 'invitations' => ['email' => 'email', 'sent' => 'sent', 'expires' => 'expires']][$tab][$sort] ?? null;

        if ($column === null) {
            return new TableSort($tab === 'members' ? 'member' : 'expires', 'asc');
        }

        return new TableSort($column, str_starts_with($requested, '-') ? 'desc' : 'asc');
    }

    /** @param  Builder<*>  $query */
    private function searchMembers(Builder $query, string $value): Builder
    {
        [$users] = $this->tables();
        $like = '%'.$this->escape($value).'%';

        return $query->where(fn (Builder $inner) => $inner->whereLike("{$users}.name", $like, caseSensitive: false)->orWhereLike("{$users}.email", $like, caseSensitive: false));
    }

    /** @param  Builder<*>  $query */
    private function withRole(Builder $query, Workspace $workspace, string $role): Builder
    {
        [, $memberships] = $this->tables();
        $pivot = Config::modelHasRolesTable();
        $roles = Config::rolesTable();
        $team = Config::teamForeignKey();
        $morphKey = Config::morphKey();

        return $query->whereIn("{$memberships}.user_id", DB::table($pivot)
            ->join($roles, "{$roles}.id", '=', "{$pivot}.".app(PermissionRegistrar::class)->pivotRole)
            ->where("{$pivot}.model_type", (new (ConciergeModels::user()))->getMorphClass())
            ->where("{$roles}.name", $role)
            ->when(Config::teamsEnabled(), fn ($sub) => $sub->where("{$pivot}.{$team}", $workspace->getKey()))
            ->select("{$pivot}.{$morphKey}"));
    }

    /** The host's scope, in a nested where so an `orWhere` in it can't widen the workspace's own constraints. */
    private function applyMemberScope(Builder $query, Workspace $workspace): void
    {
        if ($this->memberScope !== null) {
            $query->where(fn (Builder $nested) => ($this->memberScope)($nested, $workspace));
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function tables(): array
    {
        $user = new (ConciergeModels::user());

        return [$user->getTable(), (new (ConciergeModels::membership()))->getTable(), $user->getKeyName()];
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function url(string $action, ?Model $subject = null): ?string
    {
        return $this->urls === null ? null : ($this->urls)($action, $subject);
    }
}
