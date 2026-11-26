<?php

namespace App\Models;

use App\Casts\NormalizedEmail;
use App\Casts\NormalizedMobile;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string|null $mobile
 * @property string|null $email
 * @property string|null $occupation
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 */
#[Fillable([
    'name',
    'mobile',
    'email',
    'occupation',
])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use BelongsToSchool, HasFactory, HasUlids, LogsActivity, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Guardian $guardian): void {
            $guardian->name = trim($guardian->name);
            $guardian->occupation = $guardian->occupation !== null && trim($guardian->occupation) !== ''
                ? trim($guardian->occupation)
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
            'mobile' => NormalizedMobile::class,
            'email' => NormalizedEmail::class,
        ];
    }

    /**
     * Configure activity logging options for the guardian.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('guardian')
            ->logOnly(['name', 'mobile', 'email', 'occupation'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get all student-guardian links for this guardian.
     *
     * @return HasMany<StudentGuardian, $this>
     */
    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    /**
     * Get all students linked to this guardian.
     *
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['id', 'school_id', 'relationship', 'is_primary'])
            ->withTimestamps();
    }
}
