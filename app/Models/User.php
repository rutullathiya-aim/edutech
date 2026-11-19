<?php

namespace App\Models;

use App\Casts\NormalizedEmail;
use App\Casts\NormalizedMobile;
use App\Enums\MembershipStatus;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $mobile
 * @property Carbon|null $mobile_verified_at
 * @property string|null $password
 * @property bool $is_platform_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'mobile',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('email')) {
                activity('profile')
                    ->causedBy(auth()->user() ?? $user)
                    ->performedOn($user)
                    ->event('email_changed')
                    ->withProperties([
                        'old' => $user->getOriginal('email'),
                        'new' => $user->email,
                    ])
                    ->log('Email address updated');
            }

            if ($user->wasChanged('mobile')) {
                activity('profile')
                    ->causedBy(auth()->user() ?? $user)
                    ->performedOn($user)
                    ->event('mobile_changed')
                    ->withProperties([
                        'old' => $user->getOriginal('mobile'),
                        'new' => $user->mobile,
                    ])
                    ->log('Mobile number updated');
            }
        });

        static::deleting(function (User $user): void {
            activity('account')
                ->causedBy(auth()->user() ?? $user)
                ->performedOn($user)
                ->event('account_deleted')
                ->log('User account deleted');
        });
    }

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_platform_admin' => false,
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
            'mobile' => NormalizedMobile::class,
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Get all memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations sent by the user.
     *
     * @return HasMany<Invitation, $this>
     */
    public function sentInvitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    /**
     * Get all schools the user belongs to (read-only: create memberships via the Membership model).
     *
     * @return BelongsToMany<School, $this>
     */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'memberships')
            ->withPivot(['id', 'status', 'joined_at', 'disabled_at'])
            ->withTimestamps();
    }

    /**
     * Get all active schools the user has an active membership in.
     *
     * Excludes suspended, cancelled, expired-trial, and soft-deleted schools,
     * as well as disabled memberships.
     *
     * @return BelongsToMany<School, $this>
     */
    public function activeSchools(): BelongsToMany
    {
        return $this->schools()
            ->wherePivot('status', MembershipStatus::Active)
            ->active();
    }

    /**
     * Get the user's default active school.
     */
    public function defaultSchool(): ?School
    {
        return $this->activeSchools()->first();
    }

    /**
     * Determine if the user has any school memberships (active or disabled).
     */
    public function hasAnyMembership(): bool
    {
        return $this->memberships()->exists();
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
