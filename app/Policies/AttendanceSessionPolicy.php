<?php

namespace App\Policies;

use App\Concerns\AuthorizesSchoolPermissions;
use App\Enums\SchoolPermission;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Holiday;
use App\Models\School;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AttendanceSessionPolicy
{
    use AuthorizesSchoolPermissions;

    /**
     * Determine whether the user can view attendance sessions in the current school.
     */
    public function viewAny(User $user): bool
    {
        return $this->canForCurrentSchool($user, SchoolPermission::AttendanceView);
    }

    /**
     * Determine whether the user can view a specific attendance session.
     */
    public function view(User $user, AttendanceSession $session): bool
    {
        return $this->canForSchoolRecord($user, SchoolPermission::AttendanceView, $session->school_id);
    }

    /**
     * Determine whether the user can mark or edit attendance for a section on the given date.
     *
     * Weekly off days and holidays block creating new sessions; existing sessions remain editable by Owner/Admin.
     */
    public function mark(
        User $user,
        Section $section,
        CarbonInterface $date,
        ?AttendanceSession $existingSession = null,
    ): bool {
        $school = CurrentSchool::get();

        if (! $school instanceof School || $section->school_id !== $school->id) {
            return false;
        }

        $hasActiveMembership = $user->memberships()
            ->where('school_id', $section->school_id)
            ->active()
            ->exists();

        if (! $hasActiveMembership) {
            return false;
        }

        $canManage = $this->canForSchoolRecord($user, SchoolPermission::AttendanceManage, $section->school_id);
        $canMarkAssigned = $this->canForSchoolRecord($user, SchoolPermission::AttendanceMark, $section->school_id);

        if (! $canManage && ! $canMarkAssigned) {
            return false;
        }

        $timezone = $school->timezone !== '' ? $school->timezone : 'Asia/Kolkata';
        $targetDateStr = $date->format('Y-m-d');
        $targetDate = CarbonImmutable::createFromFormat('!Y-m-d', $targetDateStr, $timezone)->startOfDay();
        $today = $school->today();
        $todayStr = $today->toDateString();

        if ($targetDateStr > $todayStr) {
            return false;
        }

        $academicYear = $section->relationLoaded('academicYear')
            ? $section->academicYear
            : $section->academicYear()->first();

        if (! $academicYear instanceof AcademicYear || $academicYear->isClosed()) {
            return false;
        }

        if (
            $targetDateStr < $academicYear->starts_on->toDateString()
            || $targetDateStr > $academicYear->ends_on->toDateString()
        ) {
            return false;
        }

        $hasExistingSession = $existingSession instanceof AttendanceSession
            ? ($existingSession->school_id === $section->school_id
                && $existingSession->section_id === $section->id
                && $existingSession->date->toDateString() === $targetDateStr)
            : AttendanceSession::query()
                ->where('school_id', $section->school_id)
                ->where('section_id', $section->id)
                ->where('date', $targetDateStr)
                ->exists();

        if (! ($hasExistingSession && $canManage)) {
            if ($school->isWeeklyOff($targetDate)) {
                return false;
            }

            $isHoliday = Holiday::query()
                ->where('school_id', $section->school_id)
                ->whereDate('date', $targetDateStr)
                ->exists();

            if ($isHoliday) {
                return false;
            }
        }

        if ($canManage) {
            return true;
        }

        return $section->class_teacher_user_id === $user->id
            && $targetDateStr === $todayStr;
    }
}
