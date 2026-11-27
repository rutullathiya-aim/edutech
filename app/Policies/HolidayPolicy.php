<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view holidays for the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::AttendanceView);
    }

    /**
     * Determine whether the user can view a specific holiday.
     */
    public function view(User $user, Holiday $holiday): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AttendanceView, $holiday->school_id);
    }

    /**
     * Determine whether the user can create holidays in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::AttendanceManage);
    }

    /**
     * Determine whether the user can delete a specific holiday.
     */
    public function delete(User $user, Holiday $holiday): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AttendanceManage, $holiday->school_id);
    }
}
