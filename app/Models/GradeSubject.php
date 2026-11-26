<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\GradeSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $grade_id
 * @property string $subject_id
 * @property bool $is_optional
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read Grade $grade
 * @property-read Subject $subject
 */
#[Fillable([
    'grade_id',
    'subject_id',
    'is_optional',
    'sort_order',
])]
class GradeSubject extends Model
{
    /** @use HasFactory<GradeSubjectFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_optional' => false,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (GradeSubject $gradeSubject): void {
            if ((! $gradeSubject->exists || $gradeSubject->isDirty('grade_id')) && $gradeSubject->resolveParentModel(Grade::class, $gradeSubject->grade_id)?->isArchived()) {
                throw new LogicException('Cannot assign subjects to an archived grade.');
            }

            if ((! $gradeSubject->exists || $gradeSubject->isDirty('subject_id')) && $gradeSubject->resolveParentModel(Subject::class, $gradeSubject->subject_id)?->isArchived()) {
                throw new LogicException('Cannot assign an archived subject to a grade.');
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
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Configure activity logging options for the grade subject.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('grade_subject')
            ->logOnly(['grade_id', 'subject_id', 'is_optional', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the grade this curriculum entry belongs to.
     *
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * Get the subject this curriculum entry points to.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
