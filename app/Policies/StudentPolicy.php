<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view the students directory in the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsView);
    }

    /**
     * Determine whether the user can view a specific student record.
     */
    public function view(User $user, Student $student): bool
    {
        return $this->canViewStudentRecord($user, $student);
    }

    /**
     * Determine whether the user can view sensitive student fields (category, religion, Aadhaar last 4, APAAR, PEN).
     */
    public function viewSensitive(User $user, ?Student $student = null): bool
    {
        return $student instanceof Student
            ? ($this->canForSchoolRecord($user, SchoolPermission::StudentsViewSensitive, $student->school_id)
                && $this->canViewStudentRecord($user, $student))
            : $this->canForCurrentSchool($user, SchoolPermission::StudentsViewSensitive);
    }

    /**
     * Determine whether the user can admit students in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsManage);
    }

    /**
     * Determine whether the user can update a student profile or their guardians.
     */
    public function update(User $user, Student $student): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsManage, $student->school_id);
    }

    /**
     * Determine whether the user can import students into the current school.
     */
    public function import(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::StudentsImport);
    }

    /**
     * Students are never hard-deleted; leaving uses dedicated status transitions later.
     */
    public function delete(User $user, Student $student): bool
    {
        return false;
    }

    /**
     * Scope student record visibility.
     *
     * Teachers have school-wide student visibility today; narrow Teacher access
     * to assigned sections here once class-teacher assignments are introduced.
     */
    protected function canViewStudentRecord(User $user, Student $student): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::StudentsView, $student->school_id);
    }
}
