<?php

namespace App\Models;

use App\Casts\NormalizedEmail;
use App\Casts\NormalizedMobile;
use App\Enums\SchoolRole;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\ScopedBySchool;
use App\Models\Scopes\SchoolScope;
use Carbon\CarbonInterface;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $id
 * @property string $school_id
 * @property string|null $email
 * @property string|null $mobile
 * @property SchoolRole $role
 * @property string $token_hash
 * @property string|null $invited_by
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read School $school
 * @property-read User|null $inviter
 */
#[Fillable([
    'email',
    'mobile',
    'role',
    'expires_at',
])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToSchool, HasFactory, HasUlids, ScopedBySchool;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation): void {
            if (empty($invitation->expires_at)) {
                $days = (int) config('invitations.expiry_days', 7);
                $invitation->expires_at = now()->addDays($days);
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
            'email' => NormalizedEmail::class,
            'mobile' => NormalizedMobile::class,
            'role' => SchoolRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Scope query to only pending invitations.
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Check if the invitation is pending.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Accept the invitation.
     *
     * @throws LogicException
     */
    public function accept(): void
    {
        if (! $this->isPending()) {
            throw new LogicException('Cannot accept an invitation that is not pending.');
        }

        $this->accepted_at = now();
        $this->save();
    }

    /**
     * Revoke the invitation.
     *
     * @throws LogicException
     */
    public function revoke(): void
    {
        if ($this->accepted_at !== null) {
            throw new LogicException('Cannot revoke an invitation that has already been accepted.');
        }

        if ($this->revoked_at !== null) {
            return;
        }

        $this->revoked_at = now();
        $this->save();
    }

    /**
     * Create a new invitation with a generated token.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function createWithToken(array $attributes): array
    {
        $rawToken = static::generateToken();

        $invitation = (new self)->forceFill($attributes);
        $invitation->token_hash = static::hashToken($rawToken);
        $invitation->save();

        return [$invitation, $rawToken];
    }

    /**
     * Find a pending invitation by its raw token.
     *
     * Looking up an invitation by token happens before any tenant school is known,
     * so we explicitly skip the SchoolScope filter by class name, leaving all other scopes intact.
     */
    public static function findPendingByToken(string $rawToken): ?self
    {
        return static::withoutGlobalScope(SchoolScope::class)
            ->pending()
            ->where('token_hash', static::hashToken($rawToken))
            ->first();
    }

    /**
     * Generate a new cryptographically secure raw token.
     */
    public static function generateToken(): string
    {
        return Str::random(40);
    }

    /**
     * Hash the raw token for storage.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
