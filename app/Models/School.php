<?php

namespace App\Models;

use App\Casts\NormalizedEmail;
use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use App\Enums\SchoolStatus;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $trial_ends_at
 * @property int|null $student_limit
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
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
        ];
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
}
