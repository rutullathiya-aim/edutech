<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view sections for the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::SectionsView);
    }

    /**
     * Determine whether the user can view a specific section.
     */
    public function view(User $user, Section $section): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SectionsView, $section->school_id);
    }

    /**
     * Determine whether the user can create sections in the current school.
     */
    public function create(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::SectionsManage);
    }

    /**
     * Determine whether the user can update a specific section.
     */
    public function update(User $user, Section $section): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SectionsManage, $section->school_id);
    }

    /**
     * Determine whether the user can delete a specific section.
     */
    public function delete(User $user, Section $section): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::SectionsManage, $section->school_id);
    }
}
