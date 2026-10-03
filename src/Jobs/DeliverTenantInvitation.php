<?php

namespace Tey\Concierge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;
use SensitiveParameter;
use Tey\Concierge\Concierge;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Notifications\TenantInvitationNotification;
use Tey\Concierge\Support\ConciergeModels;

/**
 * NOT tenant-aware, on purpose: a tenant invitation exists before any workspace.
 * It resolves the invitation by id and touches no workspace, team or tenant.
 * A multitenancy host must list this class in multitenancy.not_tenant_aware_jobs,
 * or its queue layer will delete the job (and the email) for lacking a tenant.
 */
class DeliverTenantInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $invitationId,
        public int $generation,
        #[SensitiveParameter] public string $token,
    ) {
        $this->afterCommit();
    }

    public static function queueFor(TenantInvitation $invitation, #[SensitiveParameter] string $token): void
    {
        if (app(Concierge::class)->deliversTenantInvitations()) {
            static::dispatch($invitation->getKey(), $invitation->generation, $token);
        }
    }

    public function handle(Concierge $concierge): void
    {
        $invitation = ConciergeModels::tenantInvitation()::query()->find($this->invitationId);

        if (! $invitation || ! $invitation->isPending() || $invitation->isExpired()
            || $invitation->generation !== $this->generation
            || ! hash_equals($invitation->token_hash, TenantInvitation::hashToken($this->token))) {
            return;
        }

        $url = $concierge->tenantInvitationUrl($invitation, $this->token);

        if ($url === null) {
            return;
        }

        Notification::route('mail', $invitation->email)->notifyNow(new TenantInvitationNotification(
            appName: (string) config('app.name'),
            url: $url,
            expiresAt: $invitation->expires_at,
        ));

        ConciergeModels::tenantInvitation()::query()
            ->whereKey($invitation->getKey())
            ->where('generation', $this->generation)
            ->increment('send_count', 1, ['last_sent_at' => now()]);
    }
}
