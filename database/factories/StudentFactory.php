<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Sequence counter for generating unique sequential admission numbers.
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
            'admission_no' => fn (): string => 'ADM-2026-'.str_pad((string) (++static::$sequence), 4, '0', STR_PAD_LEFT),
            'admission_date' => '2026-04-01',
            'name' => fn (): string => fake()->firstName().' '.fake()->lastName(),
            'date_of_birth' => fn (): string => fake()->dateTimeBetween('-16 years', '-5 years')->format('Y-m-d'),
            'gender' => fn (): Gender => fake()->randomElement([Gender::Male, Gender::Female]),
            'social_category' => null,
            'religion' => null,
            'nationality' => 'Indian',
            'mother_tongue' => null,
            'blood_group' => null,
            'apaar_id' => null,
            'pen' => null,
            'aadhaar_last4' => null,
            'address_line' => null,
            'city' => null,
            'state' => null,
            'pincode' => null,
            'previous_school' => null,
            'previous_tc_no' => null,
            'status' => StudentStatus::Active,
            'consent_recorded_at' => null,
            'consent_recorded_by' => null,
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
