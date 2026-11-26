<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use App\Models\Scopes\SchoolScope;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Factories\SectionFactory;
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
 * @property string $academic_year_id
 * @property string $grade_id
 * @property string $name
 * @property int|null $capacity
 * @property string|null $room
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read AcademicYear $academicYear
 * @property-read Grade $grade
 */
#[Fillable([
    'name',
    'capacity',
    'room',
    'academic_year_id',
    'grade_id',
])]
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Section $section): void {
            if ($section->exists && $section->isDirty('academic_year_id')) {
                /** @var string|null $originalYearId */
                $originalYearId = $section->getOriginal('academic_year_id');

                if ($originalYearId !== null && $section->resolveParentModel(AcademicYear::class, $originalYearId)?->isClosed()) {
                    throw new LogicException('Cannot modify a section belonging to a closed academic year.');
                }
            }

            if ($section->resolveParentModel(AcademicYear::class, $section->academic_year_id)?->isClosed()) {
                throw new LogicException('Cannot save a section in a closed academic year.');
            }

            if ((! $section->exists || $section->isDirty('grade_id')) && $section->resolveParentModel(Grade::class, $section->grade_id)?->isArchived()) {
                throw new LogicException('Cannot assign an archived grade to a section.');
            }
        });
    }

    /**
     * Resolve a parent school-owned model within the current school or matching school_id.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    private function resolveParentModel(string $modelClass, ?string $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        if (CurrentSchool::check()) {
            return $modelClass::find($id);
        }

        if (empty($this->school_id)) {
            return null;
        }

        return $modelClass::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $this->school_id)
            ->find($id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /**
     * Configure activity logging options for the section.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('section')
            ->logOnly(['name', 'capacity', 'room', 'academic_year_id', 'grade_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the academic year this section belongs to.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the grade this section belongs to.
     *
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }
}
