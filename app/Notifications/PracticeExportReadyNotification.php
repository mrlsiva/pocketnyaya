<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Tells the named recipient that their closing practice's export can be downloaded. */
class PracticeExportReadyNotification extends Notification
{
    public function __construct(private string $practiceName, private Carbon $expiresAt) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your practice export is ready')
            ->line("The export for {$this->practiceName} is ready for you to download.")
            ->action('Download the export', route('offboarding.mine'))
            ->line('It is available until '.$this->expiresAt->format('d M Y').'. Only you can download it, after signing in.');
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['message' => "The export for {$this->practiceName} is ready to download.", 'link' => route('offboarding.mine')];
    }
}
