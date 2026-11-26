<?php

use App\Actions\AcademicYears\MakeAcademicYearCurrent;
use App\Concerns\AcademicYearValidationRules;
use App\Exceptions\NoCurrentSchoolException;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\School;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Helper to expose AcademicYearValidationRules for feature tests.
 */
function academicYearValidator(array $data, ?string $academicYearId = null): Illuminate\Validation\Validator
{
    $rulesProvider = new class
    {
        use AcademicYearValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(?string $id = null): array
        {
            return $this->academicYearRules($id);
        }
    };

    return Validator::make($data, $rulesProvider->rules($academicYearId));
}

it('creates an academic year with a 26-character ULID', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $academicYear = AcademicYear::factory()->for($school)->create();

    expect($academicYear->id)->toBeString()
        ->and(strlen($academicYear->id))->toBe(26)
        ->and(Str::isUlid($academicYear->id))->toBeTrue();
});

it('isolates academic years to the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->create(['name' => '2026-27']);

    CurrentSchool::set($schoolB);
    AcademicYear::factory()->for($schoolB)->create(['name' => '2026-27']);

    CurrentSchool::set($schoolA);
    $results = AcademicYear::all();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->id)->toBe($yearA->id);
});

it('fails closed when querying academic years without a current school', function () {
    CurrentSchool::forget();

    expect(fn () => AcademicYear::count())->toThrow(NoCurrentSchoolException::class);
});

it('auto-fills school_id when creating an academic year in a tenant context', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::create([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);

    expect($year->school_id)->toBe($school->id);
});

it('refuses cross-school writes when another school is current', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);

    $year = new AcademicYear([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);
    $year->school_id = $schoolB->id;

    expect(fn () => $year->save())
        ->toThrow(LogicException::class, 'Cross-school write refused');
});

it('prevents changing school_id after an academic year is created', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $year = AcademicYear::factory()->for($schoolA)->create();

    $year->school_id = $schoolB->id;

    expect(fn () => $year->save())
        ->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

it('enforces at most one current academic year per school at the database level', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    AcademicYear::factory()->for($school)->current()->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);

    $second = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $second->is_current = true;

    expect(fn () => $second->save())->toThrow(UniqueConstraintViolationException::class);
});

it('allows different schools to each have a current academic year simultaneously', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->current()->create();

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->current()->create();

    CurrentSchool::set($schoolA);
    expect(AcademicYear::current()->pluck('id')->all())->toBe([$yearA->id]);

    CurrentSchool::set($schoolB);
    expect(AcademicYear::current()->pluck('id')->all())->toBe([$yearB->id]);
});

it('switches the current academic year off the previous year and onto the chosen year with an audit entry', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $oldYear = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);
    $newYear = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    app(MakeAcademicYearCurrent::class)->handle($newYear);

    expect($oldYear->fresh()->is_current)->toBeFalse()
        ->and($oldYear->fresh()->current_flag)->toBeNull()
        ->and($newYear->fresh()->is_current)->toBeTrue()
        ->and($newYear->fresh()->current_flag)->toBe(1);

    $audit = Activity::where('event', 'academic_year_marked_current')
        ->where('subject_id', $newYear->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->school_id)->toBe($school->id)
        ->and($audit->properties?->get('previous_academic_year_id'))->toBe($oldYear->id);
});

it('refuses marking a closed academic year as current via MakeAcademicYearCurrent', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $closedYear = AcademicYear::factory()->for($school)->closed()->create();

    expect(fn () => app(MakeAcademicYearCurrent::class)->handle($closedYear))
        ->toThrow(LogicException::class, 'A closed academic year cannot be marked as current.');

    expect($closedYear->fresh()->is_current)->toBeFalse();
});

it('refuses marking a closed academic year as current directly on the model', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $closedYear = AcademicYear::factory()->for($school)->closed()->create();
    $closedYear->is_current = true;

    expect(fn () => $closedYear->save())
        ->toThrow(LogicException::class, 'A closed academic year cannot be marked as current.');
});

it('refuses an end date on or before the start date even without validation', function (string $startsOn, string $endsOn) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    expect(fn () => AcademicYear::create([
        'name' => '2026-27',
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
    ]))->toThrow(InvalidArgumentException::class, 'The academic year end date must be after the start date.');
})->with([
    'end before start' => ['2027-04-01', '2026-03-31'],
    'end equal to start' => ['2027-04-01', '2027-04-01'],
]);

it('rejects invalid academic year name formats', function (string $badName) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $validator = academicYearValidator([
        'name' => $badName,
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
})->with([
    'year only' => ['2027'],
    'two-digit start' => ['27-28'],
    'slash separator' => ['2027/28'],
    'four-digit end' => ['2027-2028'],
    'non-numeric' => ['abcd-ef'],
]);

it('enforces unique academic year name per school while allowing duplicate names across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $existing = AcademicYear::factory()->for($schoolA)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $sameSchoolFail = academicYearValidator([
        'name' => '2026-27',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);
    expect($sameSchoolFail->fails())->toBeTrue()
        ->and($sameSchoolFail->errors()->has('name'))->toBeTrue();

    // Updating the same record preserves its own name cleanly
    $editSelfPass = academicYearValidator([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ], $existing->id);
    expect($editSelfPass->passes())->toBeTrue();

    // Different school may reuse the exact same name
    CurrentSchool::set($schoolB);
    $otherSchoolPass = academicYearValidator([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    expect($otherSchoolPass->passes())->toBeTrue();
});

it('rejects overlapping dates within the same school while allowing adjacent dates and editing self', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $existing = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $overlapTouch = academicYearValidator([
        'name' => '2027-28',
        'starts_on' => '2027-03-31',
        'ends_on' => '2028-03-31',
    ]);
    expect($overlapTouch->fails())->toBeTrue()
        ->and($overlapTouch->errors()->has('ends_on'))->toBeTrue();

    $adjacentPass = academicYearValidator([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);
    expect($adjacentPass->passes())->toBeTrue();

    $editSelfPass = academicYearValidator([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-30',
    ], $existing->id);
    expect($editSelfPass->passes())->toBeTrue();
});

it('rejects academic years longer than 18 months while allowing 18 months or shorter', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $tooLong = academicYearValidator([
        'name' => '2026-28',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-10-02',
    ]);
    expect($tooLong->fails())->toBeTrue()
        ->and($tooLong->errors()->has('ends_on'))->toBeTrue();

    $exactEighteenMonths = academicYearValidator([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-10-01',
    ]);
    expect($exactEighteenMonths->passes())->toBeTrue();
});

it('protects school_id, is_current, closed_at, and current_flag against mass assignment', function (string $attribute, mixed $value) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    expect(fn () => AcademicYear::create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        $attribute => $value,
    ]))->toThrow(MassAssignmentException::class);
})->with([
    'school_id' => ['school_id', '01HZX9Y8B7C6D5E4F3G2H1J0K0'],
    'is_current' => ['is_current', true],
    'closed_at' => ['closed_at', '2027-04-01 00:00:00'],
    'current_flag' => ['current_flag', 1],
]);

it('logs date changes to the activity log with school_id and only changed allowed attributes', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $year->update([
        'ends_on' => '2027-04-15',
    ]);

    /** @var Activity|null $activity */
    $activity = Activity::where('log_name', 'academic_year')
        ->where('event', 'updated')
        ->where('subject_id', $year->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBe($school->id);

    $changes = $activity->attribute_changes?->toArray() ?? [];
    expect($changes)->toBeArray()
        ->and(array_keys($changes['attributes'] ?? []))->toBe(['ends_on'])
        ->and(array_keys($changes['old'] ?? []))->toBe(['ends_on']);

    $beforeCount = Activity::where('subject_id', $year->id)->count();
    $year->touch();
    expect(Activity::where('subject_id', $year->id)->count())->toBe($beforeCount);
});
