<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'disabled_at' => null,
        ];
    }

    /**
     * Indicate that the membership is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MembershipStatus::Active,
            'disabled_at' => null,
        ]);
    }

    /**
     * Indicate that the membership is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MembershipStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }
}
