<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Models\Concerns\BelongsToSchool;
use Carbon\CarbonInterface;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
    use BelongsToSchool, HasFactory, HasUlids;

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
     * Get the user that the membership belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve the member's highest-ranking staff role within this membership's school.
     */
    public function staffRole(): ?SchoolRole
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        return $user instanceof User
            ? $user->staffRoleForSchool($this->school_id)
            : null;
    }

    /**
     * Determine if the membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * Scope a query to only include active memberships.
     *
     * @param  Builder<Membership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active);
    }

    /**
     * Scope a query to memberships whose user holds at least one of the given roles in a school.
     *
     * @param  Builder<Membership>  $query
     * @param  list<SchoolRole|string>|SchoolRole  $roles
     */
    public function scopeWithSchoolRole(Builder $query, array|SchoolRole $roles, ?string $schoolId = null): void
    {
        $roleList = is_array($roles) ? $roles : [$roles];
        $roleNames = array_map(
            static fn (SchoolRole|string $role): string => $role instanceof SchoolRole ? $role->value : $role,
            $roleList,
        );

        if ($roleNames === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $this->getTable();

        $query->whereExists(static function (QueryBuilder $subQuery) use ($roleNames, $schoolId, $table): void {
            $subQuery->selectRaw('1')
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', "{$table}.user_id")
                ->where('model_has_roles.model_type', User::class)
                ->whereIn('roles.name', $roleNames);

            if ($schoolId !== null) {
                $subQuery->where('model_has_roles.school_id', $schoolId);
            } else {
                $subQuery->whereColumn('model_has_roles.school_id', "{$table}.school_id");
            }
        });
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
