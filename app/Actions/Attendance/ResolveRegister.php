<?php

namespace App\Actions\Attendance;

use App\Actions\Attendance\Data\ResolvedRegister;
use App\Actions\Attendance\Data\ResolvedRegisterRow;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AttendanceSession;
use App\Models\Section;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

class ResolveRegister
{
    /**
     * Resolve who belongs on the daily register for a section and date in exactly two queries without modifying state.
     */
    public function handle(Section $section, CarbonInterface|string $date): ResolvedRegister
    {
        $school = CurrentSchool::getOrFail();

        if ($section->school_id !== $school->id) {
            throw (new ModelNotFoundException)->setModel(Section::class, [$section->id]);
        }

        $dateStr = CarbonImmutable::parse($date)->toDateString();

        // Query 1: Load the existing daily session if one has already been saved.
        $session = AttendanceSession::query()
            ->where('section_id', $section->id)
            ->where('date', $dateStr)
            ->first();

        $applyEligibleScope = static function (Builder $query) use ($section, $dateStr): void {
            $query->whereNull('ar.id')
                ->where('enrollments.section_id', '=', $section->id)
                ->whereDate('enrollments.enrolled_on', '<=', $dateStr)
                ->where(static function (Builder $statusQuery) use ($dateStr): void {
                    $statusQuery->where('enrollments.status', '=', EnrollmentStatus::Active->value)
                        ->orWhere(static function (Builder $leftQuery) use ($dateStr): void {
                            $leftQuery->where('enrollments.status', '=', EnrollmentStatus::Left->value)
                                ->whereDate('enrollments.left_on', '>', $dateStr);
                        });
                });
        };

        // Query 2: Fetch session records and/or currently eligible unmarked enrollments (never duplicating an already-marked student).
        $rawRows = DB::table('enrollments')
            ->join('students', static function (JoinClause $join) use ($school): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.school_id', '=', $school->id);
            })
            ->leftJoin('attendance_records as ar', static function (JoinClause $join) use ($school, $dateStr): void {
                $join->on('ar.enrollment_id', '=', 'enrollments.id')
                    ->where('ar.school_id', '=', $school->id)
                    ->where('ar.date', '=', $dateStr);
            })
            ->where('enrollments.school_id', '=', $school->id)
            ->where(static function (Builder $query) use ($session, $applyEligibleScope): void {
                if ($session instanceof AttendanceSession) {
                    $query->where('ar.attendance_session_id', '=', $session->id)
                        ->orWhere($applyEligibleScope);

                    return;
                }

                $applyEligibleScope($query);
            })
            ->orderByRaw('CASE WHEN enrollments.roll_number IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('enrollments.roll_number')
            ->orderBy('students.name')
            ->get([
                'enrollments.id as enrollment_id',
                'enrollments.student_id as student_id',
                'enrollments.roll_number as roll_number',
                'students.name as student_name',
                'ar.status as attendance_status',
                'ar.remark as attendance_remark',
            ]);

        $rows = $rawRows->map(static function (stdClass $raw): ResolvedRegisterRow {
            /** @var string|null $rawStatus */
            $rawStatus = is_string($raw->attendance_status) ? $raw->attendance_status : null;

            return new ResolvedRegisterRow(
                enrollmentId: (string) $raw->enrollment_id,
                studentId: (string) $raw->student_id,
                rollNumber: is_numeric($raw->roll_number) ? (int) $raw->roll_number : null,
                studentName: (string) $raw->student_name,
                status: $rawStatus !== null ? AttendanceStatus::from($rawStatus) : null,
                remark: is_string($raw->attendance_remark) ? $raw->attendance_remark : null,
            );
        });

        return new ResolvedRegister(
            session: $session,
            version: $session instanceof AttendanceSession ? $session->version : 0,
            rows: $rows,
        );
    }
}
