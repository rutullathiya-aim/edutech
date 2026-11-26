<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeSubject>
 */
class GradeSubjectFactory extends Factory
{
    private static int $sequence = 1;

    /**
     * Reset the sequential sort order counter for deterministic tests.
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
        $order = self::$sequence++;

        return [
            'school_id' => School::factory(),
            'grade_id' => fn (array $attributes) => Grade::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'subject_id' => fn (array $attributes) => Subject::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'is_optional' => false,
            'sort_order' => $order,
        ];
    }

    /**
     * Indicate that the grade subject is optional.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_optional' => true,
        ]);
    }
}
