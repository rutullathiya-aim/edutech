<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
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
 * @property CarbonImmutable $date
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read AcademicYear $academicYear
 */
#[Fillable([
    'academic_year_id',
    'date',
    'name',
])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Holiday $holiday): void {
            if ($holiday->exists && $holiday->isDirty('academic_year_id')) {
                /** @var string|null $originalYearId */
                $originalYearId = $holiday->getOriginal('academic_year_id');

                if ($originalYearId !== null && $holiday->resolveParentModel(AcademicYear::class, $originalYearId)?->isClosed()) {
                    throw new LogicException('Cannot modify a holiday belonging to a closed academic year.');
                }
            }

            if ($holiday->resolveParentModel(AcademicYear::class, $holiday->academic_year_id)?->isClosed()) {
                throw new LogicException('Cannot save a holiday in a closed academic year.');
            }
        });

        static::deleting(function (Holiday $holiday): void {
            /** @var string|null $persistedYearId */
            $persistedYearId = $holiday->getOriginal('academic_year_id') ?? $holiday->academic_year_id;

            if ($holiday->resolveParentModel(AcademicYear::class, $persistedYearId)?->isClosed()) {
                throw new LogicException('Cannot delete a holiday in a closed academic year.');
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
            'date' => 'date',
        ];
    }

    /**
     * Configure activity logging options for holidays.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('holiday')
            ->logOnly(['academic_year_id', 'date', 'name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the academic year this holiday belongs to.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
