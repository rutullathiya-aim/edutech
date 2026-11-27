<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Individual student attendance entry for one enrollment on one calendar date.
 *
 * Per-model activity logging is intentionally omitted so TakeAttendance writes a single summary audit entry per save.
 *
 * @property string $id
 * @property string $school_id
 * @property string $attendance_session_id
 * @property string $enrollment_id
 * @property CarbonImmutable $date
 * @property AttendanceStatus $status
 * @property string|null $remark
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read AttendanceSession $session
 * @property-read Enrollment $enrollment
 */
#[Fillable([
    'attendance_session_id',
    'enrollment_id',
    'date',
    'status',
    'remark',
])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use BelongsToSchool, HasFactory, HasUlids, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (AttendanceRecord $record): void {
            $session = $record->resolveParentModel(AttendanceSession::class, $record->attendance_session_id);

            if (
                $session instanceof AttendanceSession
                && $record->resolveParentModel(AcademicYear::class, $session->academic_year_id)?->isClosed()
            ) {
                throw new LogicException('Cannot save an attendance record in a closed academic year.');
            }
        });

        static::deleting(function (AttendanceRecord $record): void {
            $session = $record->resolveParentModel(AttendanceSession::class, $record->attendance_session_id);

            if (
                $session instanceof AttendanceSession
                && $record->resolveParentModel(AcademicYear::class, $session->academic_year_id)?->isClosed()
            ) {
                throw new LogicException('Cannot delete an attendance record in a closed academic year.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * Get the parent attendance session.
     *
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    /**
     * Get the enrollment associated with this attendance record.
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
