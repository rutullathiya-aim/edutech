<?php

namespace App\Actions\Students;

use App\Actions\Students\Concerns\InteractsWithStudentRecords;
use App\Concerns\StudentValidationRules;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateStudent
{
    use InteractsWithStudentRecords, StudentValidationRules;

    /**
     * Update a student's profile attributes (including editable admission number) inside the current school.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Student $student, array $data): Student
    {
        $school = CurrentSchool::getOrFail();

        $normalized = $this->normalizeStudentData($data);
        $attemptedAdmissionNo = (string) ($normalized['admission_no'] ?? '');

        try {
            return DB::transaction(function () use ($actor, $student, $school, $normalized, $attemptedAdmissionNo): Student {
                $lockedStudent = $this->lockAndAuthorizeStudent($actor, $student);

                $payload = Gate::forUser($actor)->allows('viewSensitive', $lockedStudent)
                    ? $normalized
                    : Arr::except($normalized, Student::VIEW_SENSITIVE_FIELDS);

                $validated = $this->validateWithFriendlyAdmissionSuggestion(
                    $actor,
                    $payload,
                    $this->studentProfileRules(
                        $school->id,
                        $lockedStudent->id,
                        $payload['date_of_birth'] ?? null,
                    ),
                    $attemptedAdmissionNo,
                    $lockedStudent->id,
                );

                $lockedStudent->fill($validated);

                if ($lockedStudent->isDirty()) {
                    $lockedStudent->save();
                }

                return $lockedStudent->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowFriendlyStudentConstraintException($actor, $exception, $attemptedAdmissionNo);
        }
    }
}
