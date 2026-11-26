<?php

namespace App\Models;

use App\Enums\SubjectKind;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\SubjectFactory;
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
 * @property string|null $code
 * @property SubjectKind $kind
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 */
#[Fillable([
    'name',
    'code',
    'kind',
])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Subject $subject): void {
            $subject->name = trim($subject->name);
            $subject->code = $subject->code !== null && trim($subject->code) !== ''
                ? trim($subject->code)
                : null;
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
            'kind' => SubjectKind::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging options for the subject.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subject')
            ->logOnly(['name', 'code', 'kind', 'archived_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get all grade-curriculum assignments for this subject.
     *
     * @return HasMany<GradeSubject, $this>
     */
    public function gradeSubjects(): HasMany
    {
        return $this->hasMany(GradeSubject::class);
    }

    /**
     * Determine whether the subject has been archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Scope a query to only include active (unarchived) subjects.
     *
     * @param  Builder<Subject>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope a query to order subjects alphabetically within kind.
     *
     * @param  Builder<Subject>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('kind')->orderBy('name');
    }
}
