<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
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
            'student_id' => fn (array $attributes) => Student::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'academic_year_id' => function (array $attributes) {
                if (isset($attributes['section_id']) && is_string($attributes['section_id'])) {
                    $section = Section::withoutGlobalScope(SchoolScope::class)
                        ->find($attributes['section_id']);

                    if ($section !== null) {
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
            'roll_number' => null,
            'status' => EnrollmentStatus::Active,
            'enrolled_on' => '2026-04-01',
            'left_on' => null,
        ];
    }

    /**
     * Bind the enrollment to an existing section's school and academic year.
     */
    public function forSection(Section $section): static
    {
        return $this->state([
            'school_id' => $section->school_id,
            'academic_year_id' => $section->academic_year_id,
            'section_id' => $section->id,
        ]);
    }
}
