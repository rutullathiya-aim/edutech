<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can manage school-level attendance and calendar settings.
     */
    public function manageSettings(User $user, ?School $school = null): bool
    {
        return $school instanceof School
            ? $this->canForSchoolRecord($user, SchoolPermission::AttendanceManage, $school->id)
            : $this->canForCurrentSchool($user, SchoolPermission::AttendanceManage);
    }
}
