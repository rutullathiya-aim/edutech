<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    private static int $sequence = 1;

    /**
     * Reset the sequential section counter for deterministic tests.
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
            'academic_year_id' => fn (array $attributes) => AcademicYear::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'grade_id' => fn (array $attributes) => Grade::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'name' => substr("Sec {$index}", 0, 10),
            'capacity' => 40,
            'room' => "Room {$index}",
        ];
    }
}
