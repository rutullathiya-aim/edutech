<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Carbon\CarbonInterface;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $school_id
 * @property string $user_id
 * @property MembershipStatus $status
 * @property CarbonInterface $joined_at
 * @property CarbonInterface|null $disabled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read School $school
 * @property-read User $user
 */
#[Fillable([
    'joined_at',
])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory, HasUlids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MembershipStatus::Active,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Get the school that the membership belongs to.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user that the membership belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Disable the membership.
     */
    public function disable(): void
    {
        $this->status = MembershipStatus::Disabled;
        $this->disabled_at = now();
        $this->save();
    }

    /**
     * Re-activate the membership.
     */
    public function activate(): void
    {
        $this->status = MembershipStatus::Active;
        $this->disabled_at = null;
        $this->save();
    }
}
