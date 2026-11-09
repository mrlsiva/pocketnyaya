<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OdrInvitationNotification extends Notification
{
    public function __construct(private string $token, private string $disputeNumber, private string $role) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to an online dispute resolution')
            ->line("You have been invited to take part in dispute {$this->disputeNumber} as ".str_replace('_', ' ', $this->role).'.')
            ->line('Taking part is voluntary. You may want to get independent legal advice.')
            ->action('View the invitation', route('odr.invitations.show', $this->token))
            ->line('This invitation expires in 14 days.');
    }
}
