<?php

namespace Tey\Concierge\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class InvitationNotification extends Notification
{
    public function __construct(
        public string $workspaceName,
        public string $url,
        public Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have been invited to join :workspace', ['workspace' => $this->workspaceName]))
            ->line(__('You have been invited to join :workspace.', ['workspace' => $this->workspaceName]))
            ->action(__('View invitation'), $this->url)
            ->line(__('This invitation expires :date.', ['date' => $this->expiresAt->toDayDateTimeString()]));
    }
}
