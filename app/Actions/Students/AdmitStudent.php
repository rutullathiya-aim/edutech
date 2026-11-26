<?php

namespace App\Actions\Students;

use App\Actions\Students\Concerns\InteractsWithStudentRecords;
use App\Concerns\StudentValidationRules;
use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdmitStudent
{
    use InteractsWithStudentRecords, StudentValidationRules;

    /**
     * Atomically admit a new student, create or link 1 to 3 guardians, record DPDP consent, and enroll them.
     *
     * @param  array<string, mixed>  $studentData
     * @param  list<array<string, mixed>>  $guardiansData
     * @param  array<string, mixed>  $enrollmentData
     */
    public function handle(
        User $actor,
        array $studentData,
        array $guardiansData,
        array $enrollmentData,
        bool $consentConfirmed,
    ): Student {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('create', Student::class);

        $normalizedStudent = $this->normalizeStudentData($studentData);
        $normalizedGuardians = array_map(
            fn (array $guardian): array => $this->normalizeGuardianData($guardian),
            $guardiansData,
        );
        $normalizedEnrollment = $this->normalizeEnrollmentData($enrollmentData, $normalizedStudent);

        $academicYearId = is_string($normalizedEnrollment['academic_year_id'] ?? null)
            ? $normalizedEnrollment['academic_year_id']
            : null;
        $sectionId = is_string($normalizedEnrollment['section_id'] ?? null)
            ? $normalizedEnrollment['section_id']
            : null;
        $gradeId = is_string($normalizedEnrollment['grade_id'] ?? null)
            ? $normalizedEnrollment['grade_id']
            : null;

        $rules = array_merge(
            $this->studentProfileRules($school->id, null, $normalizedStudent['date_of_birth'] ?? null),
            $this->enrollmentPlacementRules($school->id, $academicYearId, $sectionId, $gradeId),
            $this->admitGuardiansRules($school->id),
            ['consent_confirmed' => ['required', 'accepted']],
        );

        $payload = array_merge($normalizedStudent, $normalizedEnrollment, [
            'guardians' => $normalizedGuardians,
            'consent_confirmed' => $consentConfirmed,
        ]);

        $attemptedAdmissionNo = (string) ($normalizedStudent['admission_no'] ?? '');
        $validated = $this->validateWithFriendlyAdmissionSuggestion($actor, $payload, $rules, $attemptedAdmissionNo);
        $this->assertGuardianInvariants($normalizedGuardians);

        try {
            return DB::transaction(function () use ($actor, $validated, $normalizedGuardians): Student {
                $enrolledOn = (string) $validated['enrolled_on'];

                $section = $this->lockAndVerifyEnrollableSection(
                    (string) $validated['academic_year_id'],
                    (string) $validated['section_id'],
                    $enrolledOn,
                );

                $student = new Student($this->extractStudentAttributes($validated));
                $student->status = StudentStatus::Active;
                $student->consent_recorded_at = now();
                $student->consent_recorded_by = $actor->id;
                $student->save();

                foreach ($normalizedGuardians as $guardianInput) {
                    $guardianId = $guardianInput['guardian_id'];

                    if (is_string($guardianId)) {
                        $guardian = Guardian::query()
                            ->whereKey($guardianId)
                            ->lockForUpdate()
                            ->firstOrFail();
                    } else {
                        $guardian = Guardian::create([
                            'name' => $guardianInput['name'],
                            'mobile' => $guardianInput['mobile'],
                            'email' => $guardianInput['email'],
                            'occupation' => $guardianInput['occupation'],
                        ]);
                    }

                    StudentGuardian::create([
                        'student_id' => $student->id,
                        'guardian_id' => $guardian->id,
                        'relationship' => $guardianInput['relationship'],
                        'is_primary' => $guardianInput['is_primary'],
                    ]);
                }

                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $section->academic_year_id,
                    'section_id' => $section->id,
                    'roll_number' => $validated['roll_number'] ?? null,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_on' => $enrolledOn,
                ]);

                return $student->load([
                    'enrollments.section.grade',
                    'enrollments.academicYear',
                    'studentGuardians.guardian',
                    'guardians',
                ]);
            });
        } catch (QueryException $exception) {
            $this->rethrowFriendlyStudentConstraintException($actor, $exception, $attemptedAdmissionNo);
        }
    }

    /**
     * Normalize enrollment placement fields for validation.
     *
     * @param  array<string, mixed>  $enrollmentData
     * @param  array<string, mixed>  $normalizedStudent
     * @return array<string, mixed>
     */
    private function normalizeEnrollmentData(array $enrollmentData, array $normalizedStudent): array
    {
        $academicYearId = $enrollmentData['academic_year_id'] ?? null;
        $sectionId = $enrollmentData['section_id'] ?? null;
        $gradeId = $enrollmentData['grade_id'] ?? null;
        $rollNumber = $enrollmentData['roll_number'] ?? null;
        $enrolledOn = $enrollmentData['enrolled_on'] ?? ($normalizedStudent['admission_date'] ?? null);

        if (is_string($rollNumber)) {
            $trimmedRoll = trim($rollNumber);
            $rollNumber = $trimmedRoll !== '' ? (ctype_digit($trimmedRoll) ? (int) $trimmedRoll : $trimmedRoll) : null;
        }

        return [
            'academic_year_id' => is_string($academicYearId) && trim($academicYearId) !== '' ? trim($academicYearId) : null,
            'section_id' => is_string($sectionId) && trim($sectionId) !== '' ? trim($sectionId) : null,
            'grade_id' => is_string($gradeId) && trim($gradeId) !== '' ? trim($gradeId) : null,
            'roll_number' => $rollNumber,
            'enrolled_on' => $enrolledOn,
        ];
    }

    /**
     * Build nested validation rules for the 1 to 3 guardians on admission.
     *
     * @return array<string, array<int, mixed>>
     */
    private function admitGuardiansRules(string $schoolId): array
    {
        $linkRules = $this->guardianLinkRules($schoolId);

        return [
            'guardians' => ['required', 'array', 'min:1', 'max:3'],
            'guardians.*.guardian_id' => $linkRules['guardian_id'],
            'guardians.*.name' => ['required_without:guardians.*.guardian_id', 'nullable', 'string', 'max:150'],
            'guardians.*.mobile' => $linkRules['mobile'],
            'guardians.*.email' => $linkRules['email'],
            'guardians.*.occupation' => $linkRules['occupation'],
            'guardians.*.relationship' => $linkRules['relationship'],
            'guardians.*.is_primary' => $linkRules['is_primary'],
        ];
    }

    /**
     * Verify primary guardian, duplicate guardian link, and primary-mobile invariants.
     *
     * @param  list<array<string, mixed>>  $normalizedGuardians
     */
    private function assertGuardianInvariants(array $normalizedGuardians): void
    {
        $primaryGuardians = collect($normalizedGuardians)
            ->filter(fn (array $guardian): bool => (bool) ($guardian['is_primary'] ?? false))
            ->values();

        if ($primaryGuardians->count() !== 1) {
            throw ValidationException::withMessages([
                'guardians' => __('Exactly one guardian must be marked as the primary contact.'),
            ]);
        }

        $linkedGuardianIds = collect($normalizedGuardians)
            ->pluck('guardian_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();

        if ($linkedGuardianIds->count() !== $linkedGuardianIds->unique()->count()) {
            throw ValidationException::withMessages([
                'guardians' => __('The same guardian cannot be linked twice to the same student.'),
            ]);
        }

        /** @var array<string, mixed> $primaryGuardian */
        $primaryGuardian = $primaryGuardians->first();
        $primaryGuardianId = $primaryGuardian['guardian_id'] ?? null;

        $primaryHasMobile = is_string($primaryGuardianId) && $primaryGuardianId !== ''
            ? Guardian::query()->whereKey($primaryGuardianId)->whereNotNull('mobile')->exists()
            : is_string($primaryGuardian['mobile'] ?? null) && $primaryGuardian['mobile'] !== '';

        if (! $primaryHasMobile) {
            throw ValidationException::withMessages([
                'guardians' => __('The primary guardian must have a mobile number.'),
            ]);
        }
    }

    /**
     * Pick fillable student attributes from the validated payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractStudentAttributes(array $validated): array
    {
        $fillableKeys = (new Student)->getFillable();

        return array_intersect_key($validated, array_flip($fillableKeys));
    }
}
