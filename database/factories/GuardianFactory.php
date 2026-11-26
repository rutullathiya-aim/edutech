<?php

namespace Database\Factories;

use App\Models\Guardian;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
{
    /**
     * Sequence counter for generating unique guardian contact numbers.
     */
    protected static int $sequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fn (): string => fake()->firstName().' '.fake()->lastName(),
            'mobile' => fn (): string => '98'.str_pad((string) (++static::$sequence), 8, '0', STR_PAD_LEFT),
            'email' => fn (): string => fake()->unique()->safeEmail(),
            'occupation' => null,
        ];
    }

    /**
     * Reset the factory sequence counter.
     */
    public static function resetSequence(): void
    {
        static::$sequence = 0;
    }
}
