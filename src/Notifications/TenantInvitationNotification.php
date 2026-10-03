<?php

namespace Tey\Concierge\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification
{
    public function __construct(
        public string $appName,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => $this->appName]))
            ->line(__('You have been invited to :app and will get your own workspace.', ['app' => $this->appName]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('This invitation expires :date.', ['date' => $this->expiresAt->toDayDateTimeString()]));
    }
}
