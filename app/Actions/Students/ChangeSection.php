<?php

namespace App\Actions\Students;

use App\Actions\Students\Concerns\InteractsWithStudentRecords;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeSection
{
    use InteractsWithStudentRecords;

    /**
     * Move an existing enrollment to another section within the same academic year in place.
     */
    public function handle(
        User $actor,
        Enrollment $enrollment,
        Section $section,
        ?int $rollNumber = null,
    ): Enrollment {
        $school = CurrentSchool::getOrFail();

        try {
            return DB::transaction(function () use ($actor, $school, $enrollment, $section, $rollNumber): Enrollment {
                $lockedEnrollment = Enrollment::query()
                    ->whereKey($enrollment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $lockedEnrollment);

                if (! $lockedEnrollment->isActive()) {
                    throw ValidationException::withMessages([
                        'section_id' => __('Only active enrollments can change sections.'),
                    ]);
                }

                $lockedSection = Section::query()
                    ->whereKey($section->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedSection->academic_year_id !== $lockedEnrollment->academic_year_id) {
                    throw ValidationException::withMessages([
                        'section_id' => __('Cannot move an enrollment to a section in a different academic year.'),
                    ]);
                }

                $this->lockAndVerifyEnrollableSection(
                    $lockedEnrollment->academic_year_id,
                    $lockedSection->id,
                );

                Validator::make(
                    ['roll_number' => $rollNumber],
                    [
                        'roll_number' => [
                            'nullable',
                            'integer',
                            'min:1',
                            'max:65535',
                            Rule::unique(Enrollment::class, 'roll_number')
                                ->where(fn ($query) => $query
                                    ->where('school_id', $school->id)
                                    ->where('section_id', $lockedSection->id))
                                ->ignore($lockedEnrollment->id),
                        ],
                    ],
                )->validate();

                $lockedEnrollment->section_id = $lockedSection->id;
                $lockedEnrollment->roll_number = $rollNumber;

                if ($lockedEnrollment->isDirty()) {
                    $lockedEnrollment->save();
                }

                return $lockedEnrollment->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowFriendlyStudentConstraintException($exception, '');
        }
    }
}
