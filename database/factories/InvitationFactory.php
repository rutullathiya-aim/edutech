<?php

namespace Database\Factories;

use App\Enums\SchoolRole;
use App\Models\Invitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => null,
            'role' => SchoolRole::Teacher,
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => User::factory(),
            'last_sent_at' => now(),
            'send_count' => 1,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Indicate that the invitation is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ]);
    }

    /**
     * Indicate that the invitation has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
            'revoked_at' => null,
        ]);
    }

    /**
     * Indicate that the invitation has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
            'accepted_at' => null,
        ]);
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
            'accepted_at' => null,
            'revoked_at' => null,
        ]);
    }

    /**
     * Indicate that the invitation is targeted to an email address.
     */
    public function forEmail(?string $email = null): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => $email ?? fake()->unique()->safeEmail(),
            'mobile' => null,
        ]);
    }

    /**
     * Indicate that the invitation is targeted to a mobile number.
     */
    public function forMobile(?string $mobile = null): static
    {
        return $this->state(fn (array $attributes) => [
            'mobile' => $mobile ?? '+919'.fake()->unique()->numerify('#########'),
            'email' => null,
        ]);
    }
}
