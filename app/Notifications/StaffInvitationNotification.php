<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use SensitiveParameter;

class StaffInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new staff invitation notification instance.
     */
    public function __construct(
        public string $schoolName,
        public string $inviterName,
        public string $roleLabel,
        #[SensitiveParameter] public string $rawToken,
        public CarbonInterface $expiresAt,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation containing only minimal invitation details.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = Route::has('invitations.show')
            ? route('invitations.show', ['token' => $this->rawToken])
            : url('/invitations/'.$this->rawToken);

        return (new MailMessage)
            ->subject(__('You are invited to join :school', ['school' => $this->schoolName]))
            ->line(__(':inviter has invited you to join :school as :role.', [
                'inviter' => $this->inviterName,
                'school' => $this->schoolName,
                'role' => $this->roleLabel,
            ]))
            ->action(__('Accept invitation'), $acceptUrl)
            ->line(__('This invitation expires on :date.', [
                'date' => $this->expiresAt->timezone('Asia/Kolkata')->format('d M Y, g:i A T'),
            ]));
    }
}
