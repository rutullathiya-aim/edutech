<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view academic years for the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::AcademicYearsView);
    }

    /**
     * Determine whether the user can view a specific academic year.
     */
    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AcademicYearsView, $academicYear->school_id);
    }

    /**
     * Determine whether the user can create academic years in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::AcademicYearsManage);
    }

    /**
     * Determine whether the user can update a specific academic year.
     */
    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AcademicYearsManage, $academicYear->school_id);
    }

    /**
     * Determine whether the user can mark an academic year as current.
     */
    public function makeCurrent(User $user, AcademicYear $academicYear): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AcademicYearsManage, $academicYear->school_id);
    }

    /**
     * Determine whether the user can delete a specific academic year.
     */
    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AcademicYearsManage, $academicYear->school_id);
    }
}
