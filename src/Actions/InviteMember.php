<?php

namespace Tey\Concierge\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MemberInvited;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\GrantSnapshot;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Scope;
use Tey\Concierge\Support\ScopedGrants;

/**
 * Invites an email into a workspace. With a scope (a record, or a Scope), the
 * invitation reaches that one record instead: its roles must be scoped roles,
 * and accepting it adds the record to the invitee's membership, creating a
 * guest membership when they have none. A scoped invitation is its own
 * pending invitation, separate from any other for the same email.
 */
class InviteMember
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy) {}

    public function handle(Member $actor, Workspace $workspace, string $email, GrantSet $grants, ?string $message = null, Model|Scope|null $scope = null): IssuedInvitation
    {
        if ($scope !== null) {
            ConciergeModels::assertWorkspaces('scoped invitations reach one record in a workspace, and app-wide mode has none');
            $scope = Scope::of($scope);

            if (! ScopedGrants::valid($grants)) {
                throw ValidationException::withMessages(['roles' => __('A scoped invitation grants scoped roles only.')]);
            }
        }

        $email = InvitationRules::normalizeEmail($email);
        $message = InvitationRules::message($message);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
        }

        return DB::transaction(function () use ($actor, $workspace, $email, $grants, $message, $scope) {
            $workspace = Locks::workspace($workspace->getKey());

            ($scope
                ? ScopedGrants::authorize($this->policy, $actor, $workspace, $grants)
                : $this->policy->authorizeGrant($actor, $workspace, $grants))->authorize();

            $membership = ConciergeModels::membership()::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('user_id', ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$email])->select((new (ConciergeModels::user()))->getKeyName()))
                ->first();
            $status = $membership?->status;

            // A guest may still be invited into the whole workspace, and anyone active to another record.
            if ($status === MembershipStatus::Active && $scope === null && ! $membership->guest) {
                throw ValidationException::withMessages(['email' => __('That person is already a member.')]);
            }

            if ($status === MembershipStatus::Active && $scope !== null && $membership->scopes()
                ->whereNull('revoked_at')
                ->where('scope_type', $scope->type)
                ->where('scope_id', $scope->id)
                ->exists()) {
                throw ValidationException::withMessages(['email' => __('That person already has access to it.')]);
            }

            if ($status === MembershipStatus::Suspended) {
                throw ValidationException::withMessages(['email' => __('That member is suspended; reinstate them instead.')]);
            }

            $pendingKey = InvitationRules::pendingKey($email, $scope);

            $pending = ConciergeModels::invitation()::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('pending_key', $pendingKey)
                ->lockForUpdate()
                ->first();

            if ($pending && ! $pending->isExpired()) {
                throw ValidationException::withMessages(['email' => __('An invitation is already pending for that email.')]);
            }

            $pending?->forceFill(['status' => InvitationStatus::Expired, 'pending_key' => null])->save();

            $snapshot = GrantSnapshot::capture($grants, $scope)
                ?? throw ValidationException::withMessages(['roles' => __('One or more roles do not exist.')]);

            $token = InvitationTokens::generate();

            $invitation = ConciergeModels::invitation()::query()->create([
                'workspace_id' => $workspace->getKey(),
                'email' => $email,
                'pending_key' => $pendingKey,
                'grants' => $snapshot['grants'],
                'grants_fingerprint' => $snapshot['fingerprint'],
                'inviter_id' => $actor->getKey(),
                'token_hash' => $token['hash'],
                'generation' => 1,
                'status' => InvitationStatus::Pending,
                'expires_at' => InvitationTokens::expiry(),
                'message' => $message,
                'scope_type' => $scope?->type,
                'scope_id' => $scope?->id,
            ]);

            MemberInvited::dispatch($workspace->getKey(), $invitation->getKey(), $actor->getKey());
            DeliverInvitation::queueFor($invitation, $token['token']);

            return new IssuedInvitation($invitation, $token['token']);
        });
    }
}
