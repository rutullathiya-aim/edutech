<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property bool $is_current
 * @property int|null $current_flag
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 */
#[Fillable([
    'name',
    'starts_on',
    'ends_on',
])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_current' => false,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (AcademicYear $academicYear): void {
            if ($academicYear->ends_on->lessThanOrEqualTo($academicYear->starts_on)) {
                throw new InvalidArgumentException('The academic year end date must be after the start date.');
            }

            if ($academicYear->is_current && $academicYear->isClosed()) {
                throw new LogicException('A closed academic year cannot be marked as current.');
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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging options for the academic year.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('academic_year')
            ->logOnly(['name', 'starts_on', 'ends_on', 'is_current', 'closed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Determine whether the academic year has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Scope a query to only include the current academic year.
     *
     * @param  Builder<AcademicYear>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
