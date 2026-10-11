<?php

namespace Tey\Concierge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;
use SensitiveParameter;
use Tey\Concierge\Concierge;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Notifications\InvitationNotification;
use Tey\Concierge\Support\ConciergeModels;

class DeliverInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $invitationId,
        public int $generation,
        #[SensitiveParameter] public string $token,
    ) {
        $this->afterCommit();
    }

    public static function queueFor(Invitation $invitation, #[SensitiveParameter] string $token): void
    {
        if (app(Concierge::class)->deliversInvitations()) {
            static::dispatch($invitation->getKey(), $invitation->generation, $token);
        }
    }

    public function handle(Concierge $concierge): void
    {
        $invitation = ConciergeModels::invitation()::query()->find($this->invitationId);

        if (! $invitation || ! $invitation->isPending() || $invitation->isExpired()
            || $invitation->generation !== $this->generation
            || ! hash_equals($invitation->token_hash, Invitation::hashToken($this->token))) {
            return;
        }

        $url = $concierge->invitationUrl($invitation, $this->token);

        if ($url === null) {
            return;
        }

        Notification::route('mail', $invitation->email)->notifyNow(new InvitationNotification(
            workspaceName: $invitation->isAppWide() ? (string) config('app.name') : $invitation->workspace->conciergeName(),
            url: $url,
            expiresAt: $invitation->expires_at,
        ));

        ConciergeModels::invitation()::query()
            ->whereKey($invitation->getKey())
            ->where('generation', $this->generation)
            ->increment('send_count', 1, ['last_sent_at' => now()]);
    }
}
