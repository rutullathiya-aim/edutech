<?php

namespace Database\Factories;

use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use App\Enums\SchoolStatus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' School',
            'status' => SchoolStatus::Trial,
            'board' => SchoolBoard::Cbse,
            'affiliation_number' => fake()->numerify('AFF-#####'),
            'udise_code' => fake()->numerify('###########'),
            'email' => fake()->safeEmail(),
            'phone' => '+91'.fake()->numerify('98########'),
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'state' => fake()->randomElement(IndianState::cases()),
            'pincode' => (string) fake()->numberBetween(100000, 999999),
            'logo_path' => null,
            'timezone' => 'Asia/Kolkata',
            'locale' => 'en',
            'trial_ends_at' => now()->addDays(30),
            'student_limit' => null,
            'settings' => null,
        ];
    }

    /**
     * Indicate that the school status is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SchoolStatus::Active,
        ]);
    }

    /**
     * Indicate that the school status is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SchoolStatus::Suspended,
        ]);
    }

    /**
     * Indicate that the school status is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SchoolStatus::Cancelled,
        ]);
    }

    /**
     * Indicate that the school's trial has expired.
     */
    public function expiredTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SchoolStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);
    }
}
