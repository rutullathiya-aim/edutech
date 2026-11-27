<?php

namespace App\Models;

use App\Casts\NormalizedEmail;
use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use App\Enums\SchoolStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property SchoolStatus $status
 * @property SchoolBoard|null $board
 * @property string|null $affiliation_number
 * @property string|null $udise_code
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property IndianState|null $state
 * @property string|null $pincode
 * @property string|null $logo_path
 * @property string $timezone
 * @property string $locale
 * @property CarbonInterface|null $trial_ends_at
 * @property int|null $student_limit
 * @property array<string, mixed>|null $settings
 * @property list<int>|null $weekly_off_days
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'name',
    'board',
    'affiliation_number',
    'udise_code',
    'email',
    'phone',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'pincode',
])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => SchoolStatus::Trial,
        'timezone' => 'Asia/Kolkata',
        'locale' => 'en',
        'weekly_off_days' => '[7]',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => NormalizedEmail::class,
            'status' => SchoolStatus::class,
            'board' => SchoolBoard::class,
            'state' => IndianState::class,
            'trial_ends_at' => 'datetime',
            'student_limit' => 'integer',
            'settings' => 'array',
            'weekly_off_days' => 'array',
        ];
    }

    /**
     * Get all holidays configured for this school.
     *
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    /**
     * Get the current calendar date ("today") in the school's configured timezone.
     */
    public function today(): CarbonImmutable
    {
        $timezone = $this->timezone !== '' ? $this->timezone : 'Asia/Kolkata';

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    /**
     * Normalize an array of ISO-8601 weekday numbers (1 = Monday .. 7 = Sunday) into a sorted unique list.
     *
     * @param  array<array-key, mixed>  $days
     * @return list<int>
     */
    public static function normalizeWeeklyOffDays(array $days): array
    {
        $normalized = [];

        foreach ($days as $day) {
            if (is_int($day) && $day >= 1 && $day <= 7) {
                $normalized[$day] = $day;
            } elseif (is_string($day) && ctype_digit($day)) {
                $intDay = (int) $day;

                if ($intDay >= 1 && $intDay <= 7) {
                    $normalized[$intDay] = $intDay;
                }
            }
        }

        $list = array_values($normalized);
        sort($list);

        return $list;
    }

    /**
     * Get the configured weekly off ISO weekdays (1 = Monday .. 7 = Sunday), defaulting to [7] (Sunday).
     *
     * @return list<int>
     */
    public function weeklyOffDays(): array
    {
        if (! is_array($this->weekly_off_days)) {
            return [7];
        }

        return self::normalizeWeeklyOffDays($this->weekly_off_days);
    }

    /**
     * Determine whether the given date falls on one of the school's configured weekly off days.
     */
    public function isWeeklyOff(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->weeklyOffDays(), true);
    }

    /**
     * Get all memberships for the school.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for the school.
     *
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Get all academic years belonging to the school.
     *
     * @return HasMany<AcademicYear, $this>
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * Get all grades belonging to the school.
     *
     * @return HasMany<Grade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /**
     * Get all subjects belonging to the school.
     *
     * @return HasMany<Subject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * Get all users belonging to the school (read-only: create memberships via the Membership model).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['id', 'status', 'joined_at', 'disabled_at'])
            ->withTimestamps();
    }

    /**
     * Determine if the school is currently active and usable.
     */
    public function isActive(): bool
    {
        if ($this->trashed()) {
            return false;
        }

        if ($this->status === SchoolStatus::Active) {
            return true;
        }

        if ($this->status === SchoolStatus::Trial) {
            return $this->trial_ends_at !== null && $this->trial_ends_at->greaterThanOrEqualTo(now());
        }

        return false;
    }

    /**
     * Scope a query to only include active and usable schools.
     *
     * Excludes suspended, cancelled, expired-trial, open-ended trials without end date,
     * and soft-deleted schools.
     *
     * @param  Builder<School>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $table = $this->getTable();

        $query->where(function (Builder $q) use ($table): void {
            $q->where("{$table}.status", SchoolStatus::Active)
                ->orWhere(function (Builder $trialQ) use ($table): void {
                    $trialQ->where("{$table}.status", SchoolStatus::Trial)
                        ->whereNotNull("{$table}.trial_ends_at")
                        ->where("{$table}.trial_ends_at", '>=', now());
                });
        });
    }

    /**
     * Get the formatted "City, State" location label for the school.
     *
     * @return Attribute<string, never>
     */
    protected function location(): Attribute
    {
        return Attribute::get(fn (): string => collect([
            $this->city,
            $this->state?->value,
        ])->filter()->implode(', '));
    }
}
