<?php

use App\Concerns\SectionValidationRules;
use App\Exceptions\NoCurrentSchoolException;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Helper to expose SectionValidationRules for feature tests.
 *
 * @param  array<string, mixed>  $data
 */
function sectionValidator(
    array $data,
    ?string $academicYearId = null,
    ?string $gradeId = null,
    ?string $sectionId = null,
): Illuminate\Validation\Validator {
    $rulesProvider = new class
    {
        use SectionValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(?string $yearId = null, ?string $gId = null, ?string $id = null): array
        {
            return $this->sectionRules($yearId, $gId, $id);
        }
    };

    $yearId = $academicYearId ?? (is_string($data['academic_year_id'] ?? null) ? $data['academic_year_id'] : null);
    $gId = $gradeId ?? (is_string($data['grade_id'] ?? null) ? $data['grade_id'] : null);

    return Validator::make($data, $rulesProvider->rules($yearId, $gId, $sectionId));
}

it('creates a section with a 26-character ULID and consistent school across related factory models', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $section = Section::factory()->recycle($school)->create();

    expect($section->id)->toBeString()
        ->and(strlen($section->id))->toBe(26)
        ->and(Str::isUlid($section->id))->toBeTrue()
        ->and($section->school_id)->toBe($school->id)
        ->and($section->academicYear->school_id)->toBe($school->id)
        ->and($section->grade->school_id)->toBe($school->id);

    CurrentSchool::forget();

    $standalone = Section::factory()->create();
    CurrentSchool::set($standalone->school);

    expect($standalone->academicYear->school_id)->toBe($standalone->school_id)
        ->and($standalone->grade->school_id)->toBe($standalone->school_id);
});

it('isolates sections to the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $sectionA = Section::factory()->recycle($schoolA)->create(['name' => 'A']);

    CurrentSchool::set($schoolB);
    Section::factory()->recycle($schoolB)->create(['name' => 'A']);

    CurrentSchool::set($schoolA);
    $results = Section::all();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->id)->toBe($sectionA->id);
});

it('fails closed when querying sections without a current school', function () {
    CurrentSchool::forget();

    expect(fn () => Section::count())->toThrow(NoCurrentSchoolException::class);
});

it('auto-fills school_id when creating a section in a tenant context', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create();
    $grade = Grade::factory()->for($school)->create();

    $section = Section::create([
        'academic_year_id' => $year->id,
        'grade_id' => $grade->id,
        'name' => 'A',
        'capacity' => 35,
        'room' => 'Room 101',
    ]);

    expect($section->school_id)->toBe($school->id);
});

it('refuses cross-school writes when another school is current', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create();
    $gradeB = Grade::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    $section = new Section([
        'academic_year_id' => $yearB->id,
        'grade_id' => $gradeB->id,
        'name' => 'A',
    ]);
    $section->school_id = $schoolB->id;

    expect(fn () => $section->save())
        ->toThrow(LogicException::class, 'Cross-school write refused');
});

it('prevents changing school_id after a section is created', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $section = Section::factory()->recycle($schoolA)->create();

    $section->school_id = $schoolB->id;

    expect(fn () => $section->save())
        ->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

it('enforces composite foreign keys at the database level against cross-school academic years and grades', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->create();
    $gradeA = Grade::factory()->for($schoolA)->create();

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create();
    $gradeB = Grade::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    expect(fn () => Section::create([
        'academic_year_id' => $yearB->id,
        'grade_id' => $gradeA->id,
        'name' => 'A',
    ]))->toThrow(QueryException::class);

    expect(fn () => Section::create([
        'academic_year_id' => $yearA->id,
        'grade_id' => $gradeB->id,
        'name' => 'A',
    ]))->toThrow(QueryException::class);
});

it('rejects another schools academic year or grade via scoped exists validation rules', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->create();
    $gradeA = Grade::factory()->for($schoolA)->create();

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create();
    $gradeB = Grade::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    $foreignYearValidator = sectionValidator([
        'academic_year_id' => $yearB->id,
        'grade_id' => $gradeA->id,
        'name' => 'A',
    ]);
    expect($foreignYearValidator->fails())->toBeTrue()
        ->and($foreignYearValidator->errors()->has('academic_year_id'))->toBeTrue();

    $foreignGradeValidator = sectionValidator([
        'academic_year_id' => $yearA->id,
        'grade_id' => $gradeB->id,
        'name' => 'A',
    ]);
    expect($foreignGradeValidator->fails())->toBeTrue()
        ->and($foreignGradeValidator->errors()->has('grade_id'))->toBeTrue();
});

it('refuses closed academic years and archived grades in both validation and the model saving hook', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $openYear = AcademicYear::factory()->for($school)->create();
    $closedYear = AcademicYear::factory()->for($school)->closed()->create();
    $activeGrade = Grade::factory()->for($school)->create();
    $archivedGrade = Grade::factory()->for($school)->archived()->create();

    $closedYearValidator = sectionValidator([
        'academic_year_id' => $closedYear->id,
        'grade_id' => $activeGrade->id,
        'name' => 'A',
    ]);
    expect($closedYearValidator->fails())->toBeTrue()
        ->and($closedYearValidator->errors()->has('academic_year_id'))->toBeTrue();

    $archivedGradeValidator = sectionValidator([
        'academic_year_id' => $openYear->id,
        'grade_id' => $archivedGrade->id,
        'name' => 'A',
    ]);
    expect($archivedGradeValidator->fails())->toBeTrue()
        ->and($archivedGradeValidator->errors()->has('grade_id'))->toBeTrue();

    expect(fn () => Section::create([
        'academic_year_id' => $closedYear->id,
        'grade_id' => $activeGrade->id,
        'name' => 'A',
    ]))->toThrow(LogicException::class, 'Cannot save a section in a closed academic year.');

    expect(fn () => Section::create([
        'academic_year_id' => $openYear->id,
        'grade_id' => $archivedGrade->id,
        'name' => 'A',
    ]))->toThrow(LogicException::class, 'Cannot assign an archived grade to a section.');

    // Editing room on an existing section remains valid if its grade is archived later, while changing to closed year fails
    $section = Section::create([
        'academic_year_id' => $openYear->id,
        'grade_id' => $activeGrade->id,
        'name' => 'A',
        'room' => '101',
    ]);

    $activeGrade->archived_at = now();
    $activeGrade->save();

    $section->update(['room' => '102']);
    expect($section->fresh()->room)->toBe('102');

    // Moving a section belonging to a closed year (or creating/editing once year closes) is refused
    $yearToClose = AcademicYear::factory()->for($school)->create();
    $freshGrade = Grade::factory()->for($school)->create();
    $sectionInSoonClosedYear = Section::create([
        'academic_year_id' => $yearToClose->id,
        'grade_id' => $freshGrade->id,
        'name' => 'B',
    ]);

    $yearToClose->closed_at = now();
    $yearToClose->save();

    expect(fn () => $sectionInSoonClosedYear->update(['room' => '202']))
        ->toThrow(LogicException::class, 'Cannot save a section in a closed academic year.');

    expect(fn () => $sectionInSoonClosedYear->update(['academic_year_id' => $openYear->id]))
        ->toThrow(LogicException::class, 'Cannot modify a section belonging to a closed academic year.');
});

it('rejects duplicate section names for the same school, year, and grade while allowing them across different grades, years, or schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $year1 = AcademicYear::factory()->for($schoolA)->create();
    $year2 = AcademicYear::factory()->for($schoolA)->create();
    $grade1 = Grade::factory()->for($schoolA)->create();
    $grade2 = Grade::factory()->for($schoolA)->create();

    $existing = Section::factory()->for($schoolA)->for($year1)->for($grade1)->create([
        'name' => 'Rose',
    ]);

    // Same school + year + grade fails validation
    $duplicateValidation = sectionValidator([
        'academic_year_id' => $year1->id,
        'grade_id' => $grade1->id,
        'name' => 'Rose',
    ]);
    expect($duplicateValidation->fails())->toBeTrue()
        ->and($duplicateValidation->errors()->has('name'))->toBeTrue();

    // Same school + year + grade fails at DB level
    expect(fn () => Section::factory()->for($schoolA)->for($year1)->for($grade1)->create([
        'name' => 'Rose',
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Editing self passes validation
    $editSelfValidation = sectionValidator([
        'academic_year_id' => $year1->id,
        'grade_id' => $grade1->id,
        'name' => 'Rose',
    ], $year1->id, $grade1->id, $existing->id);
    expect($editSelfValidation->passes())->toBeTrue();

    // Different grade in same year/school passes
    expect(sectionValidator([
        'academic_year_id' => $year1->id,
        'grade_id' => $grade2->id,
        'name' => 'Rose',
    ])->passes())->toBeTrue();

    // Different year in same grade/school passes
    expect(sectionValidator([
        'academic_year_id' => $year2->id,
        'grade_id' => $grade1->id,
        'name' => 'Rose',
    ])->passes())->toBeTrue();

    // Different school passes
    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create();
    $gradeB = Grade::factory()->for($schoolB)->create();

    expect(sectionValidator([
        'academic_year_id' => $yearB->id,
        'grade_id' => $gradeB->id,
        'name' => 'Rose',
    ])->passes())->toBeTrue();
});

it('validates section name format, capacity bounds, and room length', function (array $overrides, ?string $errorField) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create();
    $grade = Grade::factory()->for($school)->create();

    $validator = sectionValidator(array_merge([
        'academic_year_id' => $year->id,
        'grade_id' => $grade->id,
        'name' => 'Sec 1',
        'capacity' => 40,
        'room' => 'Block A 101',
    ], $overrides));

    if ($errorField === null) {
        expect($validator->passes())->toBeTrue();
    } else {
        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has($errorField))->toBeTrue();
    }
})->with([
    'valid letters digits space' => [['name' => 'Sec 1', 'capacity' => null, 'room' => null], null],
    'empty name' => [['name' => ''], 'name'],
    'name over 10 chars' => [['name' => str_repeat('A', 11)], 'name'],
    'name with hyphen' => [['name' => 'A-1'], 'name'],
    'name with html' => [['name' => '<script>'], 'name'],
    'capacity below 1' => [['capacity' => 0], 'capacity'],
    'capacity above 200' => [['capacity' => 201], 'capacity'],
    'non-integer capacity' => [['capacity' => 12.5], 'capacity'],
    'room over 30 chars' => [['room' => str_repeat('R', 31)], 'room'],
]);

it('prevents deleting a grade or an academic year that still has sections via foreign key restrictions', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create();
    $grade = Grade::factory()->for($school)->create();

    $section = Section::factory()->for($school)->for($year)->for($grade)->create();

    expect(fn () => $grade->delete())->toThrow(QueryException::class);
    expect(fn () => $year->delete())->toThrow(QueryException::class);

    $this->assertModelExists($grade);
    $this->assertModelExists($year);
    $this->assertModelExists($section);
});

it('exposes academicYear and grade relationships on Section and sections relationships on parent models', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);

    expect($section->academicYear->id)->toBe($year->id)
        ->and($section->grade->id)->toBe($grade->id)
        ->and($year->sections->pluck('id')->all())->toBe([$section->id])
        ->and($grade->sections->pluck('id')->all())->toBe([$section->id]);
});

it('protects school_id against mass assignment', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create();
    $grade = Grade::factory()->for($school)->create();

    expect(fn () => Section::create([
        'school_id' => $school->id,
        'academic_year_id' => $year->id,
        'grade_id' => $grade->id,
        'name' => 'A',
    ]))->toThrow(MassAssignmentException::class);
});

it('logs section updates to the activity log with school_id and only changed allowed attributes', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $section = Section::factory()->recycle($school)->create([
        'name' => 'A',
        'capacity' => 35,
        'room' => '101',
    ]);

    $section->update([
        'capacity' => 42,
        'room' => '105',
    ]);

    /** @var Activity|null $activity */
    $activity = Activity::where('log_name', 'section')
        ->where('event', 'updated')
        ->where('subject_id', $section->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBe($school->id);

    $changes = $activity->attribute_changes?->toArray() ?? [];
    expect($changes)->toBeArray()
        ->and(array_keys($changes['attributes'] ?? []))->toBe(['capacity', 'room'])
        ->and(array_keys($changes['old'] ?? []))->toBe(['capacity', 'room']);

    $beforeCount = Activity::where('subject_id', $section->id)->count();
    $section->touch();
    expect(Activity::where('subject_id', $section->id)->count())->toBe($beforeCount);
});
