<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
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
            'attendance_session_id' => fn (array $attributes) => AttendanceSession::factory()->state([
                'school_id' => $attributes['school_id'],
            ]),
            'enrollment_id' => function (array $attributes) {
                if (isset($attributes['attendance_session_id']) && is_string($attributes['attendance_session_id'])) {
                    $session = AttendanceSession::withoutGlobalScope(SchoolScope::class)
                        ->find($attributes['attendance_session_id']);

                    if ($session instanceof AttendanceSession) {
                        return Enrollment::factory()->state([
                            'school_id' => $attributes['school_id'],
                            'academic_year_id' => $session->academic_year_id,
                            'section_id' => $session->section_id,
                        ]);
                    }
                }

                return Enrollment::factory()->state([
                    'school_id' => $attributes['school_id'],
                ]);
            },
            'date' => function (array $attributes): string {
                if (isset($attributes['attendance_session_id']) && is_string($attributes['attendance_session_id'])) {
                    $session = AttendanceSession::withoutGlobalScope(SchoolScope::class)
                        ->find($attributes['attendance_session_id']);

                    if ($session instanceof AttendanceSession) {
                        return $session->date->toDateString();
                    }
                }

                return now()->toDateString();
            },
            'status' => AttendanceStatus::Present,
            'remark' => null,
        ];
    }
}
