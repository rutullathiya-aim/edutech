<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    private static int $dayOffset = 0;

    /**
     * Reset the deterministic date offset for factory-generated holidays.
     */
    public static function resetDayOffset(): void
    {
        self::$dayOffset = 0;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $offset = self::$dayOffset++;

        return [
            'school_id' => School::factory(),
            'academic_year_id' => fn (array $attributes) => AcademicYear::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'date' => function (array $attributes) use ($offset): string {
                if (isset($attributes['academic_year_id']) && is_string($attributes['academic_year_id'])) {
                    $year = AcademicYear::withoutGlobalScope(SchoolScope::class)
                        ->find($attributes['academic_year_id']);

                    if ($year instanceof AcademicYear) {
                        $candidate = $year->starts_on->addDays($offset % 300);

                        return ($candidate->greaterThan($year->ends_on) ? $year->ends_on : $candidate)->toDateString();
                    }
                }

                return '2026-08-15';
            },
            'name' => 'School Holiday '.($offset + 1),
        ];
    }
}
