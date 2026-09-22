<?php

namespace Tey\Concierge\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(
        public string $workspaceName,
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
            ->subject(__('You have been invited to join :workspace', ['workspace' => $this->workspaceName]))
            ->line(__('You have been invited to join :workspace.', ['workspace' => $this->workspaceName]))
            ->action(__('View invitation'), $this->url)
            ->line(__('This invitation expires :date.', ['date' => $this->expiresAt->toDayDateTimeString()]));
    }
}
