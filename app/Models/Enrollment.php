<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $section_id
 * @property int|null $roll_number
 * @property EnrollmentStatus $status
 * @property CarbonImmutable $enrolled_on
 * @property CarbonImmutable|null $left_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read Student $student
 * @property-read AcademicYear $academicYear
 * @property-read Section $section
 */
#[Fillable([
    'student_id',
    'academic_year_id',
    'section_id',
    'roll_number',
    'status',
    'enrolled_on',
    'left_on',
])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => EnrollmentStatus::Active,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Enrollment $enrollment): void {
            if ($enrollment->exists && ($enrollment->isDirty('student_id') || $enrollment->isDirty('academic_year_id'))) {
                throw new LogicException('Cannot change the student or academic year of an existing enrollment.');
            }

            if ($enrollment->resolveParentModel(AcademicYear::class, $enrollment->academic_year_id)?->isClosed()) {
                throw new LogicException('Cannot create or modify an enrollment in a closed academic year.');
            }

            if (! $enrollment->exists || $enrollment->isDirty('section_id')) {
                $section = $enrollment->resolveParentModel(Section::class, $enrollment->section_id);
                $grade = $section !== null
                    ? $enrollment->resolveParentModel(Grade::class, $section->grade_id)
                    : null;

                if ($grade?->isArchived()) {
                    throw new LogicException('Cannot enroll a student in a section belonging to an archived grade.');
                }
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
            'roll_number' => 'integer',
            'status' => EnrollmentStatus::class,
            'enrolled_on' => 'date',
            'left_on' => 'date',
        ];
    }

    /**
     * Configure activity logging options for the enrollment.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('enrollment')
            ->logOnly(['student_id', 'academic_year_id', 'section_id', 'roll_number', 'status', 'enrolled_on', 'left_on'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the student for this enrollment.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the academic year for this enrollment.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the section for this enrollment.
     *
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Determine whether this enrollment is active.
     */
    public function isActive(): bool
    {
        return $this->status === EnrollmentStatus::Active;
    }

    /**
     * Scope a query to only include active enrollments.
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Active);
    }
}
