<?php

namespace Database\Factories;

use App\Enums\SubjectKind;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    private static int $sequence = 1;

    /**
     * Reset the sequential subject counter for deterministic tests.
     */
    public static function resetSequence(): void
    {
        self::$sequence = 1;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = self::$sequence++;

        return [
            'school_id' => School::factory(),
            'name' => "Subject {$index}",
            'code' => null,
            'kind' => SubjectKind::Scholastic,
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the subject is co-scholastic.
     */
    public function coScholastic(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SubjectKind::CoScholastic,
        ]);
    }

    /**
     * Indicate that the subject is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
