<?php

namespace Database\Factories;

use App\Enums\GuardianRelationship;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentGuardian>
 */
class StudentGuardianFactory extends Factory
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
            'guardian_id' => fn (array $attributes) => Guardian::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'relationship' => GuardianRelationship::Father,
            'is_primary' => false,
        ];
    }

    /**
     * Mark this guardian link as the student's primary guardian.
     */
    public function primary(): static
    {
        return $this->state([
            'is_primary' => true,
        ]);
    }
}
