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
        public ?string $message = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('You have been invited to join :workspace', ['workspace' => $this->workspaceName]))
            ->line(__('You have been invited to join :workspace.', ['workspace' => $this->workspaceName]));

        if ($this->message !== null) {
            $mail->line($this->message);
        }

        return $mail->action(__('View invitation'), $this->url)
            ->line(__('This invitation expires :date.', ['date' => $this->expiresAt->toDayDateTimeString()]));
    }
}
