<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?string $oldEmail,
        public readonly ?string $newEmail,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Security Alert: Email Address Changed'))
            ->line(__('The email address associated with your account was recently changed to: :new_email.', [
                'new_email' => $this->newEmail,
            ]))
            ->line(__('If this wasn\'t you, please secure your account immediately or contact support.'));
    }
}
