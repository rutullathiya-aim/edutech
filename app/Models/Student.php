<?php

namespace App\Models;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\IndianState;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use Carbon\CarbonImmutable;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $school_id
 * @property string $admission_no
 * @property CarbonImmutable $admission_date
 * @property string $name
 * @property CarbonImmutable $date_of_birth
 * @property Gender $gender
 * @property SocialCategory|null $social_category
 * @property string|null $religion
 * @property string $nationality
 * @property string|null $mother_tongue
 * @property BloodGroup|null $blood_group
 * @property string|null $apaar_id
 * @property string|null $pen
 * @property string|null $aadhaar_last4
 * @property string|null $address_line
 * @property string|null $city
 * @property IndianState|null $state
 * @property string|null $pincode
 * @property string|null $previous_school
 * @property string|null $previous_tc_no
 * @property StudentStatus $status
 * @property CarbonImmutable|null $consent_recorded_at
 * @property string|null $consent_recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read School $school
 * @property-read User|null $consentRecordedBy
 */
#[Fillable([
    'admission_no',
    'admission_date',
    'name',
    'date_of_birth',
    'gender',
    'social_category',
    'religion',
    'nationality',
    'mother_tongue',
    'blood_group',
    'apaar_id',
    'pen',
    'aadhaar_last4',
    'address_line',
    'city',
    'state',
    'pincode',
    'previous_school',
    'previous_tc_no',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToSchool, HasFactory, HasUlids, ScopedBySchool;

    use LogsActivity {
        LogsActivity::shouldSkipEmptyLog as protected traitShouldSkipEmptyLog;
    }

    /**
     * Sensitive attributes gated by the students.view_sensitive permission.
     *
     * @var list<string>
     */
    public const array VIEW_SENSITIVE_FIELDS = [
        'social_category',
        'religion',
        'apaar_id',
        'pen',
        'aadhaar_last4',
    ];

    /**
     * Sensitive attributes whose values must never be recorded in activity_log.
     *
     * @var list<string>
     */
    public const array SENSITIVE_AUDIT_FIELDS = [
        'social_category',
        'religion',
        'aadhaar_last4',
        'date_of_birth',
        'apaar_id',
        'pen',
    ];

    /**
     * Nullable enum attributes where blank strings collapse to null before enum casting.
     *
     * @var list<string>
     */
    private const array NULLABLE_ENUM_FIELDS = [
        'social_category',
        'blood_group',
        'state',
    ];

    /**
     * Optional string attributes trimmed and collapsed to null when blank.
     *
     * @var list<string>
     */
    private const array NULLABLE_STRING_FIELDS = [
        'religion',
        'mother_tongue',
        'apaar_id',
        'pen',
        'aadhaar_last4',
        'address_line',
        'city',
        'pincode',
        'previous_school',
        'previous_tc_no',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'nationality' => 'Indian',
        'status' => StudentStatus::Active,
    ];

    /**
     * Sensitive field names changed during the current save operation.
     *
     * @var list<string>
     */
    protected array $pendingSensitiveAuditFields = [];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Student $student): void {
            $student->admission_no = trim($student->admission_no);
            $student->name = trim($student->name);

            $trimmedNationality = trim($student->nationality);
            $student->nationality = $trimmedNationality !== '' ? $trimmedNationality : 'Indian';

            foreach (self::NULLABLE_STRING_FIELDS as $field) {
                $value = $student->getAttribute($field);

                if (is_string($value)) {
                    $trimmed = trim($value);
                    $student->setAttribute($field, $trimmed !== '' ? $trimmed : null);
                }
            }

            $rawState = $student->getAttributes()['state'] ?? null;
            if (is_string($rawState) && trim($rawState) === '') {
                $student->setAttribute('state', null);
            }

            $student->pendingSensitiveAuditFields = $student->detectSensitiveAuditFields();
        });

        static::saved(function (Student $student): void {
            $student->pendingSensitiveAuditFields = [];
        });
    }

    /**
     * Collapse blank strings to null before enum casts validate values.
     */
    public function setAttribute($key, $value): mixed
    {
        if (in_array($key, self::NULLABLE_ENUM_FIELDS, true) && is_string($value) && trim($value) === '') {
            $value = null;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'social_category' => SocialCategory::class,
            'blood_group' => BloodGroup::class,
            'state' => IndianState::class,
            'status' => StudentStatus::class,
            'consent_recorded_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging options for the student.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('student')
            ->logOnly([
                'admission_no',
                'admission_date',
                'name',
                'gender',
                'nationality',
                'mother_tongue',
                'blood_group',
                'address_line',
                'city',
                'state',
                'pincode',
                'previous_school',
                'previous_tc_no',
                'status',
                'consent_recorded_at',
                'consent_recorded_by',
            ])
            ->logExcept(self::SENSITIVE_AUDIT_FIELDS)
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Consume the list of sensitive fields touched during the current save.
     *
     * @return list<string>
     */
    public function consumeSensitiveAuditFields(): array
    {
        $fields = $this->pendingSensitiveAuditFields;
        $this->pendingSensitiveAuditFields = [];

        return $fields;
    }

    /**
     * Keep the update audit log when only sensitive fields changed.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function shouldSkipEmptyLog(array $changes): bool
    {
        if ($this->pendingSensitiveAuditFields !== []) {
            return false;
        }

        return $this->traitShouldSkipEmptyLog($changes);
    }

    /**
     * Determine which sensitive attributes are present on create or changed on update.
     *
     * @return list<string>
     */
    private function detectSensitiveAuditFields(): array
    {
        $fields = [];
        $attributes = $this->getAttributes();

        foreach (self::SENSITIVE_AUDIT_FIELDS as $field) {
            if (! $this->exists) {
                if (($attributes[$field] ?? null) !== null) {
                    $fields[] = $field;
                }

                continue;
            }

            if ($this->isDirty($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Get the user who recorded admission consent.
     *
     * @return BelongsTo<User, $this>
     */
    public function consentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_recorded_by');
    }

    /**
     * Get all enrollment records for this student.
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Get all guardian links for this student.
     *
     * @return HasMany<StudentGuardian, $this>
     */
    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    /**
     * Get all guardians linked to this student.
     *
     * Read-only: never call attach() or sync(), which bypass ULID, school_id, audit logging, and primary-guardian guards. Use StudentGuardian actions instead.
     *
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['id', 'school_id', 'relationship', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Determine whether the student is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === StudentStatus::Active;
    }

    /**
     * Scope a query to only include active students.
     *
     * @param  Builder<Student>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', StudentStatus::Active);
    }
}
