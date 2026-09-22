<?php

namespace Tey\Concierge\Actions;

use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\WorkspaceEstablished;
use Tey\Concierge\Exceptions\WorkspaceAlreadyEstablished;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

class EstablishWorkspace
{
    use AsAction;

    public function __construct(protected Concierge $concierge) {}

    public function handle(Workspace $workspace, Member $owner): Membership
    {
        ConciergeModels::assertTeamsEnabled();

        return DB::transaction(function () use ($workspace, $owner) {
            $workspace = Locks::workspace($workspace->getKey());

            if ($ownership = Locks::ownership($workspace->getKey())) {
                $current = Locks::memberships([$ownership->membership_id])[$ownership->membership_id];

                if ((int) $current->user_id !== (int) $owner->getKey()) {
                    throw new WorkspaceAlreadyEstablished("Workspace [{$workspace->getKey()}] already has an owner.");
                }

                return $current;
            }

            $membership = ConciergeModels::membership()::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $owner->getKey())
                ->lockForUpdate()
                ->first() ?? ConciergeModels::membership()::make([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $owner->getKey(),
                ]);

            $membership->forceFill([
                'status' => MembershipStatus::Active,
                'joined_at' => $membership->joined_at ?? now(),
                'suspended_at' => null,
                'suspended_by_id' => null,
                'suspension_reason' => null,
                'removed_at' => null,
                'removed_by_id' => null,
            ])->save();

            ConciergeModels::ownership()::query()->create([
                'workspace_id' => $workspace->getKey(),
                'membership_id' => $membership->getKey(),
            ]);

            $this->concierge->context()->run(
                $workspace,
                fn () => $owner->syncRoles(Roles::resolve([Roles::owner()])),
                [$owner],
            );

            WorkspaceEstablished::dispatch($workspace->getKey(), $membership->getKey(), $owner->getKey());

            return $membership;
        });
    }
}
