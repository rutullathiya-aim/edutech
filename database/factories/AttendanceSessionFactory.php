<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    private static int $dayOffset = 0;

    /**
     * Reset the deterministic date offset for factory-created sessions.
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
            'academic_year_id' => function (array $attributes) {
                if (isset($attributes['section_id']) && is_string($attributes['section_id'])) {
                    $section = Section::withoutGlobalScope(SchoolScope::class)
                        ->find($attributes['section_id']);

                    if ($section instanceof Section) {
                        return $section->academic_year_id;
                    }
                }

                return AcademicYear::factory()->state([
                    'school_id' => $attributes['school_id'],
                ]);
            },
            'section_id' => fn (array $attributes) => Section::factory()->state([
                'school_id' => $attributes['school_id'],
                'academic_year_id' => $attributes['academic_year_id'],
            ]),
            'date' => now()->subDays($offset % 28)->toDateString(),
            'taken_by' => User::factory(),
            'taken_at' => now(),
            'updated_by' => fn (array $attributes) => $attributes['taken_by'],
            'present_count' => 0,
            'absent_count' => 0,
            'late_count' => 0,
            'half_day_count' => 0,
            'leave_count' => 0,
            'version' => 1,
        ];
    }
}
