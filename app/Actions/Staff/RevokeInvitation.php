<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithInvitationAuditAndMail;
use App\Models\Invitation;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RevokeInvitation
{
    use InteractsWithInvitationAuditAndMail;

    /**
     * Revoke an open staff invitation and record an audit entry.
     */
    public function handle(User $actor, Invitation $invitation): Invitation
    {
        CurrentSchool::getOrFail();

        return DB::transaction(function () use ($actor, $invitation): Invitation {
            $lockedInvitation = Invitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('revoke', $lockedInvitation);

            $lockedInvitation->revoke();

            $this->logInvitationActivity(
                invitation: $lockedInvitation,
                actor: $actor,
                event: 'invitation_revoked',
                description: 'Staff invitation revoked',
            );

            return $lockedInvitation;
        });
    }
}
