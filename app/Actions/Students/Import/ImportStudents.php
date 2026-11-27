<?php

namespace App\Actions\Students\Import;

use App\Actions\Students\Import\Data\StudentImportResult;
use App\Actions\Students\Import\Data\StudentImportValidationResult;
use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\ActivityLogStatus;

class ImportStudents
{
    /**
     * Maximum confirmed student imports allowed per school within one hour.
     */
    public const int HOURLY_LIMIT = 10;

    /**
     * Batch size ceilings keeping every multi-row insert safely under SQLite's 999 bind-parameter limit.
     */
    private const int STUDENT_INSERT_CHUNK = 30;

    private const int GUARDIAN_INSERT_CHUNK = 100;

    private const int LINK_INSERT_CHUNK = 100;

    private const int ENROLLMENT_INSERT_CHUNK = 80;

    private const int LOCK_CHUNK_SIZE = 500;

    public function __construct(
        private ParseStudentImportFile $parser,
        private ValidateStudentImport $validator,
    ) {}

    /**
     * Parse, re-validate inside a database transaction with locks, bulk insert students/guardians/enrollments, and log one summary entry.
     */
    public function handle(
        User $actor,
        string $academicYearId,
        string $filePath,
        string $extension,
        bool $consentConfirmed,
    ): StudentImportResult {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('import', Student::class);

        if (! $consentConfirmed) {
            throw ValidationException::withMessages([
                'consent_confirmed' => __('Confirm parental consent before importing students.'),
            ]);
        }

        $rateLimitKey = self::rateLimitKey($school->id);

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::HOURLY_LIMIT)) {
            $retryMinutes = max(1, (int) ceil(RateLimiter::availableIn($rateLimitKey) / 60));

            throw ValidationException::withMessages([
                'file' => __('Too many student imports for this school. Try again in :minutes minute(s).', [
                    'minutes' => $retryMinutes,
                ]),
            ]);
        }

        RateLimiter::hit($rateLimitKey, 3600);

        $parsed = $this->parser->handle($actor, $filePath, $extension);

        try {
            $result = DB::transaction(function () use ($actor, $school, $academicYearId, $parsed): StudentImportResult {
                School::query()
                    ->whereKey($school->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedYear = AcademicYear::query()
                    ->whereKey($academicYearId)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedYear instanceof AcademicYear) {
                    throw ValidationException::withMessages([
                        'academic_year_id' => __('The selected academic year was not found.'),
                    ]);
                }

                $validation = $this->validator->handle($actor, $lockedYear, $parsed);

                if (! $validation->isValid()) {
                    throw ValidationException::withMessages([
                        'file' => __('Validation errors were found against the current school records. Run validation again and download the error report.'),
                    ]);
                }

                $this->lockTargetStructure($validation);

                $now = now()->format('Y-m-d H:i:s');
                $studentInserts = [];
                $guardianInserts = [];
                $linkInserts = [];
                $enrollmentInserts = [];
                $sessionGuardianIds = [];
                $guardiansCreatedCount = 0;
                $guardiansReusedCount = 0;
                $canViewSensitive = $actor->can('viewSensitive', Student::class);

                foreach ($validation->preparedRows as $preparedRow) {
                    $studentId = (string) Str::ulid();
                    $student = $preparedRow['student'];

                    $studentInserts[] = [
                        'id' => $studentId,
                        'school_id' => $school->id,
                        'admission_no' => $student['admission_no'],
                        'admission_date' => $student['admission_date'],
                        'name' => $student['name'],
                        'date_of_birth' => $student['date_of_birth'],
                        'gender' => $student['gender'],
                        'social_category' => $canViewSensitive ? $student['social_category'] : null,
                        'religion' => $canViewSensitive ? $student['religion'] : null,
                        'nationality' => 'Indian',
                        'mother_tongue' => $student['mother_tongue'],
                        'blood_group' => $student['blood_group'],
                        'apaar_id' => $canViewSensitive ? $student['apaar_id'] : null,
                        'pen' => $canViewSensitive ? $student['pen'] : null,
                        'aadhaar_last4' => $canViewSensitive ? $student['aadhaar_last4'] : null,
                        'address_line' => $student['address_line'],
                        'city' => $student['city'],
                        'state' => $student['state'],
                        'pincode' => $student['pincode'],
                        'previous_school' => $student['previous_school'],
                        'previous_tc_no' => null,
                        'status' => StudentStatus::Active->value,
                        'consent_recorded_at' => $now,
                        'consent_recorded_by' => $actor->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    foreach ($preparedRow['guardians'] as $guardianData) {
                        $guardianId = $this->resolveOrQueueGuardian(
                            schoolId: $school->id,
                            now: $now,
                            guardianData: $guardianData,
                            sessionGuardianIds: $sessionGuardianIds,
                            guardianInserts: $guardianInserts,
                            guardiansCreatedCount: $guardiansCreatedCount,
                            guardiansReusedCount: $guardiansReusedCount,
                        );

                        $linkInserts[] = [
                            'id' => (string) Str::ulid(),
                            'school_id' => $school->id,
                            'student_id' => $studentId,
                            'guardian_id' => $guardianId,
                            'relationship' => $guardianData['relationship'],
                            'is_primary' => $guardianData['is_primary'] ? 1 : 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $enrollmentInserts[] = [
                        'id' => (string) Str::ulid(),
                        'school_id' => $school->id,
                        'student_id' => $studentId,
                        'academic_year_id' => $lockedYear->id,
                        'section_id' => $preparedRow['section_id'],
                        'roll_number' => $preparedRow['roll_number'],
                        'status' => EnrollmentStatus::Active->value,
                        'enrolled_on' => $preparedRow['enrolled_on'],
                        'left_on' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $activityLogStatus = app(ActivityLogStatus::class);
                $wasDisabled = $activityLogStatus->disabled();

                try {
                    $activityLogStatus->disable();

                    foreach (array_chunk($studentInserts, self::STUDENT_INSERT_CHUNK) as $chunk) {
                        DB::table('students')->insert($chunk);
                    }

                    foreach (array_chunk($guardianInserts, self::GUARDIAN_INSERT_CHUNK) as $chunk) {
                        DB::table('guardians')->insert($chunk);
                    }

                    foreach (array_chunk($linkInserts, self::LINK_INSERT_CHUNK) as $chunk) {
                        DB::table('student_guardian')->insert($chunk);
                    }

                    foreach (array_chunk($enrollmentInserts, self::ENROLLMENT_INSERT_CHUNK) as $chunk) {
                        DB::table('enrollments')->insert($chunk);
                    }
                } finally {
                    if (! $wasDisabled) {
                        $activityLogStatus->enable();
                    }
                }

                $importedCount = count($studentInserts);

                activity('student_import')
                    ->causedBy($actor)
                    ->performedOn($lockedYear)
                    ->event('students_imported')
                    ->withProperties([
                        'academic_year_id' => $lockedYear->id,
                        'academic_year' => $lockedYear->name,
                        'imported_count' => $importedCount,
                        'guardians_created' => $guardiansCreatedCount,
                        'guardians_reused' => $guardiansReusedCount,
                    ])
                    ->log("Imported {$importedCount} students into {$lockedYear->name}");

                return new StudentImportResult(
                    academicYearId: $lockedYear->id,
                    academicYearName: $lockedYear->name,
                    importedCount: $importedCount,
                    guardiansCreatedCount: $guardiansCreatedCount,
                    guardiansReusedCount: $guardiansReusedCount,
                );
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'file' => $this->friendlyConstraintMessage($exception),
            ]);
        }

        return $result;
    }

    /**
     * Compute the rate-limit key for confirmed student imports per school.
     */
    public static function rateLimitKey(string $schoolId): string
    {
        return "student-import:{$schoolId}";
    }

    /**
     * Lock referenced sections, grades, and existing guardians inside the transaction before writing.
     */
    private function lockTargetStructure(StudentImportValidationResult $validation): void
    {
        $sectionIds = array_values(array_unique(array_map(
            static fn (array $row): string => $row['section_id'],
            $validation->preparedRows,
        )));

        $existingGuardianIds = [];

        foreach ($validation->preparedRows as $row) {
            foreach ($row['guardians'] as $guardian) {
                if ($guardian['existing_guardian_id'] !== null) {
                    $existingGuardianIds[$guardian['existing_guardian_id']] = true;
                }
            }
        }

        foreach (array_chunk($sectionIds, self::LOCK_CHUNK_SIZE) as $chunk) {
            $sections = Section::query()
                ->whereIn('id', $chunk)
                ->lockForUpdate()
                ->get(['id', 'grade_id']);

            if ($sections->count() !== count($chunk)) {
                throw ValidationException::withMessages([
                    'file' => __('One or more target sections were deleted or modified. Run validation again before importing.'),
                ]);
            }

            $gradeIds = $sections->pluck('grade_id')->unique()->values()->all();
            $hasArchivedGrade = Grade::query()
                ->whereIn('id', $gradeIds)
                ->whereNotNull('archived_at')
                ->lockForUpdate()
                ->exists();

            if ($hasArchivedGrade) {
                throw ValidationException::withMessages([
                    'file' => __('One or more target grades were archived. Run validation again before importing.'),
                ]);
            }
        }

        foreach (array_chunk(array_keys($existingGuardianIds), self::LOCK_CHUNK_SIZE) as $chunk) {
            $lockedCount = Guardian::query()
                ->whereIn('id', $chunk)
                ->lockForUpdate()
                ->count();

            if ($lockedCount !== count($chunk)) {
                throw ValidationException::withMessages([
                    'file' => __('A linked guardian record changed during import. Run validation again and retry.'),
                ]);
            }
        }
    }

    /**
     * Resolve an existing DB/in-file guardian ID or queue a new guardian row for batch insertion.
     *
     * @param  array{
     *     existing_guardian_id: string|null,
     *     name: string,
     *     normalized_name_key: string,
     *     mobile: string|null,
     *     relationship: string,
     *     is_primary: bool,
     * }  $guardianData
     * @param  array<string, string>  $sessionGuardianIds
     * @param  list<array<string, mixed>>  $guardianInserts
     */
    private function resolveOrQueueGuardian(
        string $schoolId,
        string $now,
        array $guardianData,
        array &$sessionGuardianIds,
        array &$guardianInserts,
        int &$guardiansCreatedCount,
        int &$guardiansReusedCount,
    ): string {
        $lookupKey = $guardianData['mobile'] !== null
            ? "{$guardianData['mobile']}|{$guardianData['normalized_name_key']}"
            : null;

        if ($guardianData['existing_guardian_id'] !== null) {
            if ($lookupKey !== null && ! isset($sessionGuardianIds[$lookupKey])) {
                $sessionGuardianIds[$lookupKey] = $guardianData['existing_guardian_id'];
            }

            $guardiansReusedCount++;

            return $guardianData['existing_guardian_id'];
        }

        if ($lookupKey !== null && isset($sessionGuardianIds[$lookupKey])) {
            $guardiansReusedCount++;

            return $sessionGuardianIds[$lookupKey];
        }

        $guardianId = (string) Str::ulid();

        if ($lookupKey !== null) {
            $sessionGuardianIds[$lookupKey] = $guardianId;
        }

        $guardianInserts[] = [
            'id' => $guardianId,
            'school_id' => $schoolId,
            'name' => $guardianData['name'],
            'mobile' => $guardianData['mobile'],
            'email' => null,
            'occupation' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $guardiansCreatedCount++;

        return $guardianId;
    }

    /**
     * Map unique database constraint failures into safe messages without repeating cell values.
     */
    private function friendlyConstraintMessage(QueryException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'students_school_admission_unique') || str_contains($message, 'students.admission_no')) {
            return __('One or more admission numbers were claimed concurrently. Run validation again before importing.');
        }

        if (str_contains($message, 'students_school_apaar_unique') || str_contains($message, 'students.apaar_id')) {
            return __('One or more APAAR IDs were claimed concurrently. Run validation again before importing.');
        }

        if (str_contains($message, 'enr_school_section_roll_unique') || str_contains($message, 'enrollments.roll_number')) {
            return __('One or more roll numbers were assigned concurrently. Run validation again before importing.');
        }

        report($exception);

        return __('The import could not be completed because school records changed concurrently. Please try again.');
    }
}
