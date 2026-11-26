<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Subject;
use App\Models\User;

class SubjectPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view subjects and grade curriculum for the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::SubjectsView);
    }

    /**
     * Determine whether the user can view a specific subject.
     */
    public function view(User $user, Subject $subject): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SubjectsView, $subject->school_id);
    }

    /**
     * Determine whether the user can create subjects or manage grade curriculum in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::SubjectsManage);
    }

    /**
     * Determine whether the user can update a specific subject.
     */
    public function update(User $user, Subject $subject): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SubjectsManage, $subject->school_id);
    }

    /**
     * Determine whether the user can archive a specific subject.
     */
    public function archive(User $user, Subject $subject): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SubjectsManage, $subject->school_id);
    }

    /**
     * Determine whether the user can restore an archived subject.
     */
    public function restore(User $user, Subject $subject): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SubjectsManage, $subject->school_id);
    }

    /**
     * Determine whether the user can sync subjects assigned to a grade.
     */
    public function manageCurriculum(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::SubjectsManage);
    }

    /**
     * Subjects are archived rather than deleted to preserve curriculum and report history.
     */
    public function delete(User $user, Subject $subject): bool
    {
        return false;
    }

    /**
     * Subjects cannot be permanently deleted.
     */
    public function forceDelete(User $user, Subject $subject): bool
    {
        return false;
    }
}
