<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification
{
    public function __construct(public string $token, public string $tenantName, public string $role) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invitation to join {$this->tenantName}")
            ->line("You've been invited to join {$this->tenantName} as {$this->role}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires in 7 days. Sign in or register with this email address to accept it.');
    }
}
