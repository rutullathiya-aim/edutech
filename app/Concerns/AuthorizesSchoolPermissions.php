<?php

namespace App\Concerns;

use App\Enums\SchoolPermission;
use App\Models\User;
use App\Tenant\CurrentSchool;

trait AuthorizesSchoolPermissions
{
    /**
     * Determine whether the user holds a permission for the currently scoped school.
     */
    protected function canForCurrentSchool(User $user, SchoolPermission $permission): bool
    {
        $schoolId = CurrentSchool::id();

        return $schoolId !== null
            && getPermissionsTeamId() === $schoolId
            && $user->checkPermissionTo($permission->value);
    }

    /**
     * Determine whether the user holds a permission for a specific record in the current school.
     */
    protected function canForSchoolRecord(User $user, SchoolPermission $permission, string $recordSchoolId): bool
    {
        return $recordSchoolId === CurrentSchool::id()
            && $this->canForCurrentSchool($user, $permission);
    }
}
