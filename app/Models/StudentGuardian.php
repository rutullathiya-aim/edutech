<?php

namespace App\Models;

use App\Enums\GuardianRelationship;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\StudentGuardianFactory;
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
 * @property string $student_id
 * @property string $guardian_id
 * @property GuardianRelationship $relationship
 * @property bool $is_primary
 * @property int|null $primary_flag
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read Student $student
 * @property-read Guardian $guardian
 */
#[Fillable([
    'student_id',
    'guardian_id',
    'relationship',
    'is_primary',
])]
class StudentGuardian extends Model
{
    /** @use HasFactory<StudentGuardianFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'student_guardian';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (StudentGuardian $studentGuardian): void {
            if ($studentGuardian->exists && ($studentGuardian->isDirty('student_id') || $studentGuardian->isDirty('guardian_id'))) {
                throw new LogicException('Cannot change the student or guardian of an existing link. Delete the link and create a new one instead.');
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
            'relationship' => GuardianRelationship::class,
            'is_primary' => 'boolean',
            'primary_flag' => 'integer',
        ];
    }

    /**
     * Configure activity logging options for the student-guardian link.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('student_guardian')
            ->logOnly(['student_id', 'guardian_id', 'relationship', 'is_primary'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the student in this link.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the guardian in this link.
     *
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * Determine whether this guardian is the student's primary contact.
     */
    public function isPrimary(): bool
    {
        return $this->is_primary;
    }
}
