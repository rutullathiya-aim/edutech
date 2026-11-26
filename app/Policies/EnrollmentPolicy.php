<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view student enrollments in the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsView);
    }

    /**
     * Determine whether the user can view a specific enrollment record.
     */
    public function view(User $user, Enrollment $enrollment): bool
    {
        return $this->canViewEnrollmentRecord($user, $enrollment);
    }

    /**
     * Determine whether the user can create enrollments in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsManage);
    }

    /**
     * Determine whether the user can update a specific enrollment (such as section transfers).
     */
    public function update(User $user, Enrollment $enrollment): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsManage, $enrollment->school_id);
    }

    /**
     * Enrollments are preserved for academic history rather than deleted.
     */
    public function delete(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    /**
     * Scope enrollment visibility; narrow Teacher access to assigned sections once class teachers exist.
     */
    protected function canViewEnrollmentRecord(User $user, Enrollment $enrollment): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsView, $enrollment->school_id);
    }
}
