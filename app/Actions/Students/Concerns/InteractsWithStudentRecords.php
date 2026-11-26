<?php

namespace App\Actions\Students\Concerns;

use App\Actions\Students\SuggestAdmissionNumber;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait InteractsWithStudentRecords
{
    /**
     * Lock the target student inside the current school and authorize the actor to update it.
     */
    protected function lockAndAuthorizeStudent(User $actor, Student $student): Student
    {
        CurrentSchool::idOrFail();

        $lockedStudent = Student::query()
            ->whereKey($student->id)
            ->lockForUpdate()
            ->firstOrFail();

        Gate::forUser($actor)->authorize('update', $lockedStudent);

        return $lockedStudent;
    }

    /**
     * Lock and verify that a target section belongs to the open academic year, date window, and an unarchived grade.
     */
    protected function lockAndVerifyEnrollableSection(
        string $academicYearId,
        string $sectionId,
        DateTimeInterface|string|null $enrolledOn = null,
    ): Section {
        $academicYear = AcademicYear::query()
            ->whereKey($academicYearId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($academicYear->isClosed()) {
            throw ValidationException::withMessages([
                'academic_year_id' => __('Cannot enroll a student in a closed academic year.'),
            ]);
        }

        if ($enrolledOn !== null) {
            $date = CarbonImmutable::parse($enrolledOn)->startOfDay();

            if ($date->lessThan($academicYear->starts_on) || $date->greaterThan($academicYear->ends_on)) {
                throw ValidationException::withMessages([
                    'enrolled_on' => __('The enrollment date must fall within the selected academic year.'),
                ]);
            }
        }

        $section = Section::query()
            ->whereKey($sectionId)
            ->where('academic_year_id', $academicYear->id)
            ->lockForUpdate()
            ->firstOrFail();

        $grade = Grade::query()
            ->whereKey($section->grade_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($grade->isArchived()) {
            throw ValidationException::withMessages([
                'section_id' => __('Cannot enroll a student into a section of an archived grade.'),
            ]);
        }

        return $section;
    }

    /**
     * Validate student payload and surface a friendly suggestion when the admission number collides.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateWithFriendlyAdmissionSuggestion(
        User $actor,
        array $payload,
        array $rules,
        string $attemptedAdmissionNo,
        ?string $ignoreStudentId = null,
    ): array {
        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            $errors = $validator->errors();

            if ($errors->has('admission_no') && $attemptedAdmissionNo !== '') {
                $collisionExists = Student::query()
                    ->where('admission_no', $attemptedAdmissionNo)
                    ->when($ignoreStudentId !== null, fn ($query) => $query->whereKeyNot($ignoreStudentId))
                    ->exists();

                if ($collisionExists) {
                    $errors->forget('admission_no');
                    $errors->add('admission_no', $this->buildAdmissionTakenMessage($actor, $attemptedAdmissionNo));
                }
            }

            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Build a friendly admission number collision message with a next suggestion when available.
     */
    protected function buildAdmissionTakenMessage(User $actor, string $attemptedAdmissionNo): string
    {
        $suggestion = (new SuggestAdmissionNumber)->handle($actor, $attemptedAdmissionNo);

        if ($suggestion !== null) {
            return __('Admission number :no is already taken, try :suggestion.', [
                'no' => $attemptedAdmissionNo,
                'suggestion' => $suggestion,
            ]);
        }

        return __('Admission number :no is already taken.', [
            'no' => $attemptedAdmissionNo,
        ]);
    }

    /**
     * Convert database unique constraint races on student or roll fields into friendly validation errors.
     *
     * @throws QueryException
     * @throws ValidationException
     */
    protected function rethrowFriendlyStudentConstraintException(
        User $actor,
        QueryException $exception,
        string $attemptedAdmissionNo,
    ): never {
        $message = $exception->getMessage();

        if (
            str_contains($message, 'students_school_admission_unique')
            || str_contains($message, 'students.admission_no')
        ) {
            throw ValidationException::withMessages([
                'admission_no' => $this->buildAdmissionTakenMessage($actor, $attemptedAdmissionNo),
            ]);
        }

        if (
            str_contains($message, 'students_school_apaar_unique')
            || str_contains($message, 'students.apaar_id')
        ) {
            throw ValidationException::withMessages([
                'apaar_id' => __('This APAAR ID is already registered for another student in this school.'),
            ]);
        }

        if (
            str_contains($message, 'enr_school_section_roll_unique')
            || str_contains($message, 'enrollments.roll_number')
        ) {
            throw ValidationException::withMessages([
                'roll_number' => __('That roll number is already assigned in the selected section.'),
            ]);
        }

        throw $exception;
    }
}
