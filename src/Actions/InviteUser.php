<?php

namespace Tey\Concierge\Actions;

use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\UserInvited;
use Tey\Concierge\Jobs\DeliverInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\GrantSnapshot;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\InvitationTokens;
use Tey\Concierge\Support\IssuedInvitation;
use Tey\Concierge\Support\ManagedAccount;

/**
 * App-wide mode's InviteMember: invites an email into the app with roles. The
 * roster is the users table, so an email that already has an account is not
 * invited; change that user's roles instead.
 */
class InviteUser
{
    use Runnable;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, string $email, GrantSet $grants): IssuedInvitation
    {
        return ManagedAccount::mutate($actor, null, function () use ($actor, $email, $grants) {
            $email = $this->check($actor, $email, $grants);

            // The row check() read is still the one to write under its lock.
            $pending = ConciergeModels::invitation()::query()
                ->whereNull('workspace_id')
                ->where('pending_key', $email)
                ->lockForUpdate()
                ->first();

            $pending?->forceFill(['status' => InvitationStatus::Expired, 'pending_key' => null])->save();

            $snapshot = GrantSnapshot::capture($grants)
                ?? throw ValidationException::withMessages(['roles' => __('One or more roles do not exist.')]);

            $token = InvitationTokens::generate();

            $invitation = ConciergeModels::invitation()::query()->create([
                'workspace_id' => null,
                'email' => $email,
                'pending_key' => $email,
                'grants' => $snapshot['grants'],
                'grants_fingerprint' => $snapshot['fingerprint'],
                'inviter_id' => $actor->getKey(),
                'token_hash' => $token['hash'],
                'generation' => 1,
                'status' => InvitationStatus::Pending,
                'expires_at' => InvitationTokens::expiry(),
            ]);

            UserInvited::dispatch($invitation->getKey(), $actor->getKey());
            DeliverInvitation::queueFor($invitation, $token['token']);

            return new IssuedInvitation($invitation, $token['token']);
        });
    }

    /**
     * Every rule the action applies, without the write: the app-wide mode, the
     * email, the AccountPolicy's grant rules, the roster, a pending invitation
     * and that the roles exist. handle() runs it inside the write's lock, so
     * the rules see what they are about to write; a dry run or a caller
     * checking first runs it before any.
     *
     * @return string the normalized email
     */
    public function check(Member $actor, string $email, GrantSet $grants): string
    {
        ConciergeModels::assertAppWide('use InviteMember');

        $email = InvitationRules::normalizeEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
        }

        $this->policy->authorizeGrant($actor, $grants)->authorize();

        $existing = ConciergeModels::user()::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing) {
            $account = ConciergeModels::account()::query()->find($existing->getKey());

            throw ValidationException::withMessages(['email' => $account && ! $account->isActive()
                ? __('That account is deactivated; restore it instead.')
                : __('That person already has an account; change their roles instead.')]);
        }

        $pending = ConciergeModels::invitation()::query()
            ->whereNull('workspace_id')
            ->where('pending_key', $email)
            ->lockForUpdate()
            ->first();

        if ($pending && ! $pending->isExpired()) {
            throw ValidationException::withMessages(['email' => __('An invitation is already pending for that email.')]);
        }

        GrantSnapshot::capture($grants)
            ?? throw ValidationException::withMessages(['roles' => __('One or more roles do not exist.')]);

        return $email;
    }
}
