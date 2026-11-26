<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\GradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string|null $short_name
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 */
#[Fillable([
    'name',
    'short_name',
    'sort_order',
])]
class Grade extends Model
{
    /** @use HasFactory<GradeFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging options for the grade.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('grade')
            ->logOnly(['name', 'short_name', 'sort_order', 'archived_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get all sections belonging to this grade.
     *
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * Get all curriculum subject assignments belonging to this grade.
     *
     * @return HasMany<GradeSubject, $this>
     */
    public function gradeSubjects(): HasMany
    {
        return $this->hasMany(GradeSubject::class);
    }

    /**
     * Determine whether the grade has been archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Scope a query to only include active (unarchived) grades.
     *
     * @param  Builder<Grade>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope a query to order grades by their display/promotion sequence.
     *
     * @param  Builder<Grade>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }
}
