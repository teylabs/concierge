<?php

namespace Tey\Concierge\Support;

use Illuminate\Contracts\Session\Session;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;

/**
 * The workspace a signed-in user last worked in, kept in the session. It is
 * read fresh against memberships: once the user is removed, leaves or is
 * suspended, it moves to their oldest other active membership, or to none.
 */
class CurrentWorkspace
{
    public const string SESSION_KEY = 'concierge.workspace';

    public function __construct(protected Session $session) {}

    public function remember(Workspace $workspace): void
    {
        $this->session->put(self::SESSION_KEY, $workspace->getKey());
    }

    public function id(): ?int
    {
        $id = $this->session->get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /** The remembered workspace if it is still available to the user, otherwise the one it repoints to. */
    public function resolve(Member $user): ?Workspace
    {
        $active = ConciergeModels::membership()::query()
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id);

        $id = $active->contains($this->id()) ? $this->id() : $active->first();

        if ($id === null) {
            $this->forget();

            return null;
        }

        $this->session->put(self::SESSION_KEY, $id);

        /** @var Workspace|null */
        return ConciergeModels::workspace()::query()->find($id);
    }
}
