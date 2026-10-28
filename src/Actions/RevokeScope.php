<?php

namespace Tey\Concierge\Actions;

use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\InvitationRevoked;
use Tey\Concierge\Events\MembershipRemoved;
use Tey\Concierge\Events\ScopeRevoked;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\MembershipScope;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\ManagedMembership;

/**
 * Takes one record away from a membership, at once: the scope is revoked,
 * pending invitations to the same record for the member's email are revoked
 * (their links die), and when it was a guest membership's last active scope
 * the guest membership is removed. The user account is never touched, and a
 * full member keeps their membership. Revoking twice changes nothing.
 */
class RevokeScope
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy, protected Concierge $concierge) {}

    public function handle(Member $actor, MembershipScope $scope): MembershipScope
    {
        ConciergeModels::assertWorkspaces('scoped invitations reach one record in a workspace, and app-wide mode has none');

        $membership = ConciergeModels::membership()::query()->findOrFail($scope->membership_id);

        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor, $scope) {
            $this->policy->authorizeManagement($actor, $workspace)->authorize();

            $scope = ConciergeModels::membershipScope()::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if (! $scope->isActive()) {
                return $scope;
            }

            $scope->forceFill(['active' => null, 'revoked_at' => now(), 'revoked_by_id' => $actor->getKey()])->save();

            $this->revokePendingInvitations($actor, $membership, $scope);

            $removed = $membership->guest
                && $membership->status !== MembershipStatus::Removed
                && ! $membership->scopes()->whereNull('revoked_at')->exists();

            if ($removed) {
                $user = $membership->user;

                $this->concierge->context()->run($workspace, function () use ($user) {
                    $user->syncRoles([]);
                    $user->syncPermissions([]);
                }, [$user]);

                $membership->forceFill([
                    'status' => MembershipStatus::Removed,
                    'removed_at' => now(),
                    'removed_by_id' => $actor->getKey(),
                ])->save();

                MembershipRemoved::dispatch($workspace->getKey(), $membership->getKey(), $actor->getKey());
            }

            ScopeRevoked::dispatch(
                (int) $workspace->getKey(),
                (int) $membership->getKey(),
                (int) $scope->getKey(),
                $scope->scope_type,
                $scope->scope_id,
                (int) $actor->getKey(),
                $removed,
            );

            return $scope;
        });
    }

    protected function revokePendingInvitations(Member $actor, Membership $membership, MembershipScope $scope): void
    {
        $email = InvitationRules::normalizeEmail($membership->user->conciergeEmail());

        $pending = ConciergeModels::invitation()::query()
            ->where('workspace_id', $membership->workspace_id)
            ->where('scope_type', $scope->scope_type)
            ->where('scope_id', $scope->scope_id)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        foreach ($pending as $invitation) {
            $invitation->forceFill([
                'status' => InvitationStatus::Revoked,
                'pending_key' => null,
                'revoked_at' => now(),
                'revoked_by_id' => $actor->getKey(),
            ])->save();

            InvitationRevoked::dispatch($membership->workspace_id, $invitation->getKey(), $actor->getKey());
        }
    }
}
