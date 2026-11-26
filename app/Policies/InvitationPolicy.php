<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view invitations in the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StaffView);
    }

    /**
     * Determine whether the user can create invitations in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StaffManage);
    }

    /**
     * Determine whether the user can resend an open invitation.
     */
    public function resend(User $user, Invitation $invitation): bool
    {
        return $this->canManageOpenInvitation($user, $invitation);
    }

    /**
     * Determine whether the user can revoke an open invitation.
     */
    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->canManageOpenInvitation($user, $invitation);
    }

    /**
     * Check school boundary, permission, open state, and hierarchy for resend/revoke actions.
     */
    private function canManageOpenInvitation(User $user, Invitation $invitation): bool
    {
        if (! $this->canForSchoolRecord($user, SchoolPermission::StaffManage, $invitation->school_id)) {
            return false;
        }

        if ($invitation->isAccepted() || $invitation->isRevoked()) {
            return false;
        }

        $actorRole = $user->staffRoleForSchool($invitation->school_id);

        return $actorRole !== null && $actorRole->canManage($invitation->role);
    }
}
