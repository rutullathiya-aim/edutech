<?php

namespace App\Actions\Attendance;

use App\Actions\Attendance\Data\ResolvedRegister;
use App\Actions\Students\Import\StudentImportColumns;
use App\Enums\AttendanceStatus;
use App\Events\AttendanceTaken;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TakeAttendance
{
    /**
     * Batch chunk size for attendance_records upserts, keeping bind parameters well under SQLite limits.
     */
    private const int UPSERT_CHUNK_SIZE = 100;

    public function __construct(
        private ResolveRegister $resolveRegister,
    ) {}

    /**
     * Record or update daily attendance for a section inside one transaction using optimistic locking and bulk upsert.
     *
     * @param  array<array-key, mixed>  $statuses  Map of enrollment ULID => AttendanceStatus or its string backing value
     * @param  array<array-key, mixed>  $remarks  Optional map of enrollment ULID => nullable remark (max 100 chars)
     */
    public function handle(
        User $actor,
        Section $section,
        CarbonInterface|string $date,
        array $statuses,
        int $expectedVersion = 0,
        array $remarks = [],
    ): AttendanceSession {
        $school = CurrentSchool::getOrFail();
        $timezone = $school->timezone !== '' ? $school->timezone : 'Asia/Kolkata';
        $dateStr = $date instanceof CarbonInterface
            ? $date->format('Y-m-d')
            : CarbonImmutable::parse($date, $timezone)->toDateString();
        $targetDate = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr, $timezone)->startOfDay();

        Gate::forUser($actor)->authorize('mark', [AttendanceSession::class, $section, $targetDate]);

        try {
            return DB::transaction(function () use (
                $actor,
                $school,
                $section,
                $targetDate,
                $dateStr,
                $statuses,
                $expectedVersion,
                $remarks,
            ): AttendanceSession {
                $lockedSection = Section::query()
                    ->where('school_id', $school->id)
                    ->whereKey($section->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedYear = AcademicYear::query()
                    ->where('school_id', $school->id)
                    ->whereKey($lockedSection->academic_year_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedSection->setRelation('academicYear', $lockedYear);

                $lockedSession = AttendanceSession::query()
                    ->where('section_id', $lockedSection->id)
                    ->where('date', $dateStr)
                    ->lockForUpdate()
                    ->first();

                Gate::forUser($actor)->authorize('mark', [AttendanceSession::class, $lockedSection, $targetDate, $lockedSession]);

                if ($lockedSession instanceof AttendanceSession && $lockedSession->version !== $expectedVersion) {
                    $updaterName = User::query()
                        ->whereKey($lockedSession->updated_by)
                        ->value('name') ?? __('another user');

                    $timezone = $school->timezone !== '' ? $school->timezone : 'Asia/Kolkata';
                    $updatedAt = ($lockedSession->updated_at ?? $lockedSession->taken_at)->timezone($timezone);

                    throw ValidationException::withMessages([
                        'version' => __('Attendance for this class was updated by :name at :time. Reload to see the latest.', [
                            'name' => $updaterName,
                            'time' => $updatedAt->format('g:i A'),
                        ]),
                    ]);
                }

                if (! $lockedSession instanceof AttendanceSession && $expectedVersion !== 0) {
                    throw ValidationException::withMessages([
                        'version' => __('Attendance session version is out of date. Reload to see the latest.'),
                    ]);
                }

                $register = $this->resolveRegister->handle($lockedSection, $targetDate);

                [$validatedStatuses, $validatedRemarks] = $this->validateEntries($register, $statuses, $remarks);

                $isFirstSave = ! ($lockedSession instanceof AttendanceSession);
                $now = CarbonImmutable::now();
                $nowStr = $now->format('Y-m-d H:i:s');

                if ($isFirstSave) {
                    $lockedSession = new AttendanceSession([
                        'academic_year_id' => $lockedSection->academic_year_id,
                        'section_id' => $lockedSection->id,
                        'date' => $dateStr,
                    ]);
                    $lockedSession->school_id = $school->id;
                    $lockedSession->taken_by = $actor->id;
                    $lockedSession->taken_at = $now;
                    $lockedSession->updated_by = $actor->id;
                    $lockedSession->version = 1;
                } else {
                    $lockedSession->updated_by = $actor->id;
                    $lockedSession->version = $lockedSession->version + 1;
                }

                $counts = [
                    AttendanceStatus::Present->value => 0,
                    AttendanceStatus::Absent->value => 0,
                    AttendanceStatus::Late->value => 0,
                    AttendanceStatus::HalfDay->value => 0,
                    AttendanceStatus::Leave->value => 0,
                ];

                /** @var list<string> $absentEnrollmentIds */
                $absentEnrollmentIds = [];

                /** @var array<string, array{0: string|null, 1: string}> $changes */
                $changes = [];

                /** @var list<array<string, string|null>> $upsertRows */
                $upsertRows = [];

                foreach ($register->rows as $row) {
                    $newStatus = $validatedStatuses[$row->enrollmentId];
                    $counts[$newStatus->value]++;

                    if ($newStatus === AttendanceStatus::Absent) {
                        $absentEnrollmentIds[] = $row->enrollmentId;
                    }

                    if (! $isFirstSave && $row->status !== $newStatus) {
                        $changes[$row->enrollmentId] = [$row->status?->value, $newStatus->value];
                    }

                    $upsertRows[] = [
                        'id' => (string) Str::ulid(),
                        'school_id' => $school->id,
                        'attendance_session_id' => $lockedSession->id ?? '',
                        'enrollment_id' => $row->enrollmentId,
                        'date' => $dateStr,
                        'status' => $newStatus->value,
                        'remark' => $validatedRemarks[$row->enrollmentId],
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ];
                }

                $lockedSession->present_count = $counts[AttendanceStatus::Present->value];
                $lockedSession->absent_count = $counts[AttendanceStatus::Absent->value];
                $lockedSession->late_count = $counts[AttendanceStatus::Late->value];
                $lockedSession->half_day_count = $counts[AttendanceStatus::HalfDay->value];
                $lockedSession->leave_count = $counts[AttendanceStatus::Leave->value];
                $lockedSession->save();

                $sessionId = $lockedSession->id;

                foreach ($upsertRows as $index => $upsertRow) {
                    $upsertRows[$index]['attendance_session_id'] = $sessionId;
                }

                foreach (array_chunk($upsertRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DB::table('attendance_records')->upsert(
                        $chunk,
                        ['school_id', 'attendance_session_id', 'enrollment_id'],
                        ['date', 'status', 'remark', 'updated_at'],
                    );
                }

                $properties = [
                    'section_id' => $lockedSection->id,
                    'academic_year_id' => $lockedSection->academic_year_id,
                    'date' => $dateStr,
                    'counts' => $counts,
                ];

                if (! $isFirstSave) {
                    $properties['changes'] = $changes;
                }

                activity('attendance')
                    ->causedBy($actor)
                    ->performedOn($lockedSession)
                    ->event($isFirstSave ? 'attendance_taken' : 'attendance_updated')
                    ->withProperties($properties)
                    ->log($isFirstSave ? 'Attendance taken' : 'Attendance updated');

                if ($isFirstSave) {
                    $schoolId = $school->id;

                    DB::afterCommit(static function () use ($schoolId, $sessionId, $absentEnrollmentIds): void {
                        AttendanceTaken::dispatch($schoolId, $sessionId, $absentEnrollmentIds);
                    });
                }

                return $lockedSession->refresh();
            });
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'att_sessions_school_section_date_unique')) {
                throw ValidationException::withMessages([
                    'version' => __('Attendance for this class was saved concurrently. Reload to see the latest.'),
                ]);
            }

            if (str_contains($message, 'att_records_school_enrollment_date_unique')) {
                throw ValidationException::withMessages([
                    'statuses' => __('One or more students already have attendance recorded in another section for this date.'),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Validate completeness, register membership, status enum values, and remarks against ResolveRegister.
     *
     * @param  array<array-key, mixed>  $statuses
     * @param  array<array-key, mixed>  $remarks
     * @return array{0: array<string, AttendanceStatus>, 1: array<string, string|null>}
     */
    private function validateEntries(ResolvedRegister $register, array $statuses, array $remarks): array
    {
        /** @var array<string, true> $registerIds */
        $registerIds = [];

        foreach ($register->rows as $row) {
            $registerIds[$row->enrollmentId] = true;
        }

        /** @var array<string, mixed> $normalizedStatusInputs */
        $normalizedStatusInputs = [];

        foreach ($statuses as $enrollmentId => $statusValue) {
            $idKey = (string) $enrollmentId;

            if (! isset($registerIds[$idKey])) {
                throw ValidationException::withMessages([
                    'statuses' => __('One or more submitted students do not belong to this section register.'),
                ]);
            }

            $normalizedStatusInputs[$idKey] = $statusValue;
        }

        /** @var array<string, string|null> $normalizedRemarks */
        $normalizedRemarks = [];

        foreach ($remarks as $enrollmentId => $remarkValue) {
            $idKey = (string) $enrollmentId;

            if (! isset($registerIds[$idKey])) {
                throw ValidationException::withMessages([
                    'remarks' => __('One or more submitted remarks do not belong to this section register.'),
                ]);
            }

            if ($remarkValue === null) {
                $normalizedRemarks[$idKey] = null;

                continue;
            }

            if (! is_string($remarkValue)) {
                throw ValidationException::withMessages([
                    "remarks.{$idKey}" => __('Attendance remarks must be plain text.'),
                ]);
            }

            $trimmed = trim($remarkValue);

            if ($trimmed === '') {
                $normalizedRemarks[$idKey] = null;

                continue;
            }

            if (mb_strlen($trimmed) > 100) {
                throw ValidationException::withMessages([
                    "remarks.{$idKey}" => __('Attendance remarks may not exceed 100 characters.'),
                ]);
            }

            if (StudentImportColumns::startsWithDisallowedFormulaCharacter($trimmed)) {
                throw ValidationException::withMessages([
                    "remarks.{$idKey}" => __('Attendance remarks cannot start with = or @.'),
                ]);
            }

            $normalizedRemarks[$idKey] = $trimmed;
        }

        /** @var array<string, AttendanceStatus> $validatedStatuses */
        $validatedStatuses = [];

        /** @var array<string, string|null> $validatedRemarks */
        $validatedRemarks = [];

        foreach (array_keys($registerIds) as $idKey) {
            if (! array_key_exists($idKey, $normalizedStatusInputs)) {
                throw ValidationException::withMessages([
                    'statuses' => __('Mark attendance for every student on the register before saving.'),
                ]);
            }

            $rawStatus = $normalizedStatusInputs[$idKey];
            $enumStatus = match (true) {
                $rawStatus instanceof AttendanceStatus => $rawStatus,
                is_string($rawStatus) => AttendanceStatus::tryFrom($rawStatus),
                default => null,
            };

            if (! $enumStatus instanceof AttendanceStatus) {
                throw ValidationException::withMessages([
                    "statuses.{$idKey}" => __('Select a valid attendance status.'),
                ]);
            }

            $validatedStatuses[$idKey] = $enumStatus;
            $validatedRemarks[$idKey] = $normalizedRemarks[$idKey] ?? null;
        }

        return [$validatedStatuses, $validatedRemarks];
    }
}
