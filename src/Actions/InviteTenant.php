<?php

namespace Tey\Concierge\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\TenantInvited;
use Tey\Concierge\Jobs\DeliverTenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedTenantInvitation;
use Tey\Concierge\Support\PlatformOwners;

/**
 * A platform owner invites an email; accepting provisions that person's own workspace.
 */
class InviteTenant
{
    use Runnable;

    public function handle(Member $actor, string $email): IssuedTenantInvitation
    {
        PlatformOwners::authorize($actor);

        $email = InvitationRules::normalizeEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
        }

        return DB::transaction(function () use ($actor, $email) {
            $pending = ConciergeModels::tenantInvitation()::query()
                ->where('pending_key', $email)
                ->lockForUpdate()
                ->first();

            if ($pending && ! $pending->isExpired()) {
                throw ValidationException::withMessages(['email' => __('An invitation is already pending for that email.')]);
            }

            if (! config('concierge.tenant_invitations.allow_reinvite_after_accept', false)
                && ConciergeModels::tenantInvitation()::query()
                    ->where('email', $email)
                    ->where('status', InvitationStatus::Accepted)
                    ->whereNotNull('workspace_id')
                    ->exists()) {
                throw ValidationException::withMessages(['email' => __('That email already has a workspace.')]);
            }

            $pending?->forceFill(['status' => InvitationStatus::Expired, 'pending_key' => null])->save();

            $token = InvitationTokens::generate();

            // With no row to lock yet, two first-time invites race to the unique pending_key.
            try {
                $invitation = ConciergeModels::tenantInvitation()::query()->create([
                    'email' => $email,
                    'pending_key' => $email,
                    'inviter_id' => $actor->getKey(),
                    'token_hash' => $token['hash'],
                    'generation' => 1,
                    'status' => InvitationStatus::Pending,
                    'expires_at' => InvitationTokens::expiry(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => __('An invitation is already pending for that email.')]);
            }

            TenantInvited::dispatch($invitation->getKey(), $actor->getKey());
            DeliverTenantInvitation::queueFor($invitation, $token['token']);

            return new IssuedTenantInvitation($invitation, $token['token']);
        });
    }
}
