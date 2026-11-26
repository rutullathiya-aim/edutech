<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    private static int $yearSequence = 2010;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = self::$yearSequence++;
        $endYearShort = substr((string) ($startYear + 1), -2);

        return [
            'school_id' => School::factory(),
            'name' => "{$startYear}-{$endYearShort}",
            'starts_on' => "{$startYear}-04-01",
            'ends_on' => ($startYear + 1).'-03-31',
            'is_current' => false,
            'closed_at' => null,
        ];
    }

    /**
     * Indicate that the academic year is current.
     */
    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => true,
        ]);
    }

    /**
     * Indicate that the academic year is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
        ]);
    }
}
