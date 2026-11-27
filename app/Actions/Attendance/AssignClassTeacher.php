<?php

namespace App\Actions\Attendance;

use App\Enums\SchoolRole;
use App\Models\AcademicYear;
use App\Models\Membership;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignClassTeacher
{
    /**
     * Assign or unassign a class teacher for a section inside the current school and log the change.
     */
    public function handle(User $actor, Section $section, ?User $teacher): Section
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('assignClassTeacher', $section);

        return DB::transaction(function () use ($actor, $school, $section, $teacher): Section {
            $lockedSection = Section::query()
                ->whereKey($section->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('assignClassTeacher', $lockedSection);

            $academicYear = AcademicYear::query()
                ->whereKey($lockedSection->academic_year_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($academicYear->isClosed()) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('Cannot assign a class teacher in a closed academic year.'),
                ]);
            }

            $newTeacherId = null;

            if ($teacher instanceof User) {
                $membership = Membership::query()
                    ->where('school_id', $school->id)
                    ->where('user_id', $teacher->id)
                    ->active()
                    ->withSchoolRole(SchoolRole::staffCases(), $school->id)
                    ->lockForUpdate()
                    ->first();

                if (! $membership instanceof Membership) {
                    throw ValidationException::withMessages([
                        'class_teacher_user_id' => __('The selected user must have an active staff membership in this school.'),
                    ]);
                }

                $newTeacherId = $teacher->id;
            }

            $oldTeacherId = $lockedSection->class_teacher_user_id;

            if ($oldTeacherId === $newTeacherId) {
                return $lockedSection->load('classTeacher');
            }

            $lockedSection->class_teacher_user_id = $newTeacherId;
            $lockedSection->save();

            activity('section')
                ->causedBy($actor)
                ->performedOn($lockedSection)
                ->event('class_teacher_assigned')
                ->withProperties([
                    'section_id' => $lockedSection->id,
                    'academic_year_id' => $lockedSection->academic_year_id,
                    'old_class_teacher_user_id' => $oldTeacherId,
                    'new_class_teacher_user_id' => $newTeacherId,
                ])
                ->log($newTeacherId !== null
                    ? "Assigned class teacher for section {$lockedSection->name}"
                    : "Unassigned class teacher for section {$lockedSection->name}");

            return $lockedSection->refresh()->load('classTeacher');
        });
    }
}
