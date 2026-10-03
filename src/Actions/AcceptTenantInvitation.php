<?php

namespace Tey\Concierge\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use SensitiveParameter;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\TenantAcceptanceOutcome;
use Tey\Concierge\Events\TenantInvitationAccepted;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\TenantAcceptance;

/**
 * Authoritative acceptance: provisions a workspace through the host's provisioner
 * and establishes the invitee as its owner, in one transaction. A rejection or
 * failure writes nothing and leaves the invitation pending. The user is already
 * authenticated: register-or-sign-in belongs to the host.
 */
class AcceptTenantInvitation
{
    use AsAction;

    public function __construct(protected Concierge $concierge) {}

    public function handle(Member $user, #[SensitiveParameter] string $token): TenantAcceptance
    {
        $hash = TenantInvitation::hashToken($token);

        $id = ConciergeModels::tenantInvitation()::query()->where('token_hash', $hash)->value('id')
            ?? throw new InvitationRejected(InvitationState::Invalid);

        return DB::transaction(function () use ($user, $hash, $id) {
            $invitation = ConciergeModels::tenantInvitation()::query()
                ->whereKey($id)
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first() ?? throw new InvitationRejected(InvitationState::Invalid);

            if ($invitation->status === InvitationStatus::Accepted
                && (int) $invitation->accepted_by_id === (int) $user->getKey()
                && $invitation->workspace_id) {
                $workspace = ConciergeModels::workspace()::query()->findOrFail($invitation->workspace_id);

                return new TenantAcceptance(
                    $invitation,
                    $workspace,
                    $this->concierge->membership($user, $workspace) ?? throw new InvitationRejected(InvitationState::Accepted),
                    TenantAcceptanceOutcome::AlreadyProvisioned,
                );
            }

            if ($state = static::evaluate($invitation, $user)) {
                throw new InvitationRejected($state);
            }

            $provisioner = $this->concierge->workspaceProvisioner()
                ?? throw new UnsupportedConfiguration('Tenant invitations need Concierge::provisionWorkspaceUsing() to create the workspace.');

            $workspace = $provisioner($user, $invitation);

            if (! $workspace instanceof Model || ! $workspace instanceof Workspace || ! $workspace->exists) {
                throw new UnsupportedConfiguration('The workspace provisioner must return a saved Workspace model.');
            }

            $membership = EstablishWorkspace::run($workspace, $user);

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted,
                'pending_key' => null,
                'accepted_at' => now(),
                'accepted_by_id' => $user->getKey(),
                'workspace_id' => $workspace->getKey(),
            ])->save();

            TenantInvitationAccepted::dispatch(
                $invitation->getKey(),
                $workspace->getKey(),
                $membership->getKey(),
                $user->getKey(),
                TenantAcceptanceOutcome::Provisioned->value,
            );

            return new TenantAcceptance($invitation, $workspace, $membership, TenantAcceptanceOutcome::Provisioned);
        });
    }

    /** Null when the invitation may be accepted (by $user, if given), else the rejecting state. */
    public static function evaluate(TenantInvitation $invitation, ?Member $user): ?InvitationState
    {
        return match (true) {
            $invitation->status === InvitationStatus::Accepted => InvitationState::Accepted,
            $invitation->status === InvitationStatus::Revoked => InvitationState::Revoked,
            $invitation->isExpired() => InvitationState::Expired,
            $user === null => null,
            ! hash_equals($invitation->email, InvitationRules::normalizeEmail($user->conciergeEmail())) => InvitationState::WrongAccount,
            ! $user->conciergeHasVerifiedEmail() => InvitationState::Unverified,
            default => null,
        };
    }
}
