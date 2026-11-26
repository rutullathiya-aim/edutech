<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Grade;
use App\Models\User;

class GradePolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view grades for the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::GradesView);
    }

    /**
     * Determine whether the user can view a specific grade.
     */
    public function view(User $user, Grade $grade): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::GradesView, $grade->school_id);
    }

    /**
     * Determine whether the user can create grades in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::GradesManage);
    }

    /**
     * Determine whether the user can update a specific grade.
     */
    public function update(User $user, Grade $grade): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::GradesManage, $grade->school_id);
    }

    /**
     * Determine whether the user can archive a specific grade.
     */
    public function archive(User $user, Grade $grade): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::GradesManage, $grade->school_id);
    }

    /**
     * Determine whether the user can restore an archived grade.
     */
    public function restore(User $user, Grade $grade): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::GradesManage, $grade->school_id);
    }

    /**
     * Grades are archived rather than deleted to preserve historical references.
     */
    public function delete(User $user, Grade $grade): bool
    {
        return false;
    }

    /**
     * Grades cannot be permanently deleted.
     */
    public function forceDelete(User $user, Grade $grade): bool
    {
        return false;
    }
}
