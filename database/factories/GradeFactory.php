<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    private static int $sortOrderSequence = 1;

    /**
     * Reset the sequential sort order counter for deterministic tests.
     */
    public static function resetSequence(): void
    {
        self::$sortOrderSequence = 1;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = self::$sortOrderSequence++;

        return [
            'school_id' => School::factory(),
            'name' => "Grade {$order}",
            'short_name' => "G{$order}",
            'sort_order' => $order,
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the grade is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
