<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view the school's staff directory.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StaffView);
    }

    /**
     * Determine whether the user can view a specific school membership.
     */
    public function view(User $user, Membership $membership): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StaffView, $membership->school_id);
    }

    /**
     * Determine whether the user can invite staff to the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StaffManage);
    }

    /**
     * Determine whether the user can change role, disable, or re-enable the target membership.
     */
    public function update(User $user, Membership $membership): bool
    {
        if (! $this->canForSchoolRecord($user, SchoolPermission::StaffManage, $membership->school_id)) {
            return false;
        }

        if ($membership->user_id === $user->id) {
            return false;
        }

        $actorRole = $user->staffRoleForSchool($membership->school_id);
        $targetRole = $membership->staffRole();

        return $actorRole !== null
            && $targetRole !== null
            && $actorRole->canManage($targetRole);
    }

    /**
     * Staff memberships are never deleted; they are disabled instead.
     */
    public function delete(User $user, Membership $membership): bool
    {
        return false;
    }
}
