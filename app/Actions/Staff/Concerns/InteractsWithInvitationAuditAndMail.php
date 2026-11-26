<?php

namespace App\Actions\Staff\Concerns;

use App\Models\Invitation;
use App\Models\School;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SensitiveParameter;

trait InteractsWithInvitationAuditAndMail
{
    /**
     * Record a token-free audit entry for a staff invitation lifecycle event.
     *
     * @param  array<string, mixed>  $extraProperties
     */
    protected function logInvitationActivity(
        Invitation $invitation,
        User $actor,
        string $event,
        string $description,
        array $extraProperties = [],
    ): void {
        activity('staff')
            ->causedBy($actor)
            ->performedOn($invitation)
            ->event($event)
            ->withProperties([
                'role' => $invitation->role->value,
                'email' => $invitation->maskedEmail(),
                'actor_id' => $actor->id,
                ...$extraProperties,
            ])
            ->log($description);
    }

    /**
     * Queue the encrypted staff invitation email after the current DB transaction commits.
     */
    protected function dispatchInvitationMailAfterCommit(
        School $school,
        User $actor,
        Invitation $invitation,
        #[SensitiveParameter] string $rawToken,
    ): void {
        $recipientEmail = (string) $invitation->email;
        $notification = new StaffInvitationNotification(
            schoolName: $school->name,
            inviterName: $actor->name,
            roleLabel: $invitation->role->value,
            rawToken: $rawToken,
            expiresAt: $invitation->expires_at ?? now()->addDays((int) config('invitations.expiry_days', 7)),
        );

        DB::afterCommit(static function () use ($recipientEmail, $notification): void {
            Notification::route('mail', $recipientEmail)->notify($notification);
        });
    }
}
