<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Daily section attendance session storing summary counts and optimistic-lock version.
 *
 * Per-model activity logging is intentionally omitted so TakeAttendance writes a single explicit summary log entry.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $section_id
 * @property CarbonImmutable $date
 * @property string $taken_by
 * @property CarbonImmutable $taken_at
 * @property string $updated_by
 * @property int $present_count
 * @property int $absent_count
 * @property int $late_count
 * @property int $half_day_count
 * @property int $leave_count
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read AcademicYear $academicYear
 * @property-read Section $section
 * @property-read User $takenBy
 * @property-read User $updatedBy
 */
#[Fillable([
    'academic_year_id',
    'section_id',
    'date',
])]
class AttendanceSession extends Model
{
    /** @use HasFactory<AttendanceSessionFactory> */
    use BelongsToSchool, HasFactory, HasUlids, ScopedBySchool;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'present_count' => 0,
        'absent_count' => 0,
        'late_count' => 0,
        'half_day_count' => 0,
        'leave_count' => 0,
        'version' => 1,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (AttendanceSession $session): void {
            if ($session->exists && $session->isDirty('academic_year_id')) {
                /** @var string|null $originalYearId */
                $originalYearId = $session->getOriginal('academic_year_id');

                if ($originalYearId !== null && $session->resolveParentModel(AcademicYear::class, $originalYearId)?->isClosed()) {
                    throw new LogicException('Cannot modify an attendance session belonging to a closed academic year.');
                }
            }

            if ($session->resolveParentModel(AcademicYear::class, $session->academic_year_id)?->isClosed()) {
                throw new LogicException('Cannot save an attendance session in a closed academic year.');
            }
        });

        static::deleting(function (AttendanceSession $session): void {
            /** @var string|null $persistedYearId */
            $persistedYearId = $session->getOriginal('academic_year_id') ?? $session->academic_year_id;

            if ($session->resolveParentModel(AcademicYear::class, $persistedYearId)?->isClosed()) {
                throw new LogicException('Cannot delete an attendance session in a closed academic year.');
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
            'taken_at' => 'datetime',
            'present_count' => 'integer',
            'absent_count' => 'integer',
            'late_count' => 'integer',
            'half_day_count' => 'integer',
            'leave_count' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Get the academic year for this session.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the section for this session.
     *
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Get the staff member who originally took attendance.
     *
     * @return BelongsTo<User, $this>
     */
    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    /**
     * Get the staff member who last updated attendance.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the individual student attendance records belonging to this session.
     *
     * @return HasMany<AttendanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
