<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Guardian;
use App\Models\User;

class GuardianPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view guardians in the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsView);
    }

    /**
     * Determine whether the user can view a specific guardian record.
     */
    public function view(User $user, Guardian $guardian): bool
    {
        return $this->canViewGuardianRecord($user, $guardian);
    }

    /**
     * Determine whether the user can create guardians in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsManage);
    }

    /**
     * Determine whether the user can update or link a specific guardian record.
     */
    public function update(User $user, Guardian $guardian): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsManage, $guardian->school_id);
    }

    /**
     * Guardians are detached from students rather than directly deleted.
     */
    public function delete(User $user, Guardian $guardian): bool
    {
        return false;
    }

    /**
     * Scope guardian visibility to mirror student record access rules.
     */
    protected function canViewGuardianRecord(User $user, Guardian $guardian): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsView, $guardian->school_id);
    }
}
