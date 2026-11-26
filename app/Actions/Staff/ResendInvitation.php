<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithInvitationAuditAndMail;
use App\Models\Invitation;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResendInvitation
{
    use InteractsWithInvitationAuditAndMail;

    /**
     * Rotate the invitation token, extend its expiry, and resend after commit within cooldown/send limits.
     */
    public function handle(User $actor, Invitation $invitation): Invitation
    {
        $school = CurrentSchool::getOrFail();

        return DB::transaction(function () use ($school, $actor, $invitation): Invitation {
            $lockedInvitation = Invitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('resend', $lockedInvitation);

            $maxSends = (int) config('invitations.max_sends', 5);

            if ($lockedInvitation->send_count >= $maxSends) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation has reached the maximum of :max sends.', ['max' => $maxSends]),
                ]);
            }

            $cooldownSeconds = (int) config('invitations.resend_cooldown_seconds', 60);

            if ($lockedInvitation->last_sent_at !== null
                && $lockedInvitation->last_sent_at->gt(now()->subSeconds($cooldownSeconds))) {
                throw ValidationException::withMessages([
                    'invitation' => __('Please wait a minute before resending this invitation.'),
                ]);
            }

            $rawToken = Invitation::generateToken();
            $lockedInvitation->token_hash = Invitation::hashToken($rawToken);
            $lockedInvitation->expires_at = now()->addDays((int) config('invitations.expiry_days', 7));
            $lockedInvitation->last_sent_at = now();
            $lockedInvitation->send_count++;
            $lockedInvitation->save();

            $this->logInvitationActivity(
                invitation: $lockedInvitation,
                actor: $actor,
                event: 'invitation_resent',
                description: 'Staff invitation resent',
                extraProperties: ['send_count' => $lockedInvitation->send_count],
            );

            $this->dispatchInvitationMailAfterCommit($school, $actor, $lockedInvitation, $rawToken);

            return $lockedInvitation;
        });
    }
}
