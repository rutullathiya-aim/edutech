<?php

use App\Actions\Grades\CreateStandardGrades;
use App\Concerns\GradeValidationRules;
use App\Exceptions\NoCurrentSchoolException;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\School;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Helper to expose GradeValidationRules for feature tests.
 *
 * @param  array<string, mixed>  $data
 */
function gradeValidator(array $data, ?string $gradeId = null): Illuminate\Validation\Validator
{
    $rulesProvider = new class
    {
        use GradeValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(?string $id = null): array
        {
            return $this->gradeRules($id);
        }
    };

    return Validator::make($data, $rulesProvider->rules($gradeId));
}

it('creates a grade with a 26-character ULID', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $grade = Grade::factory()->for($school)->create();

    expect($grade->id)->toBeString()
        ->and(strlen($grade->id))->toBe(26)
        ->and(Str::isUlid($grade->id))->toBeTrue();
});

it('isolates grades to the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $gradeA = Grade::factory()->for($schoolA)->create(['name' => 'Class 1', 'sort_order' => 1]);

    CurrentSchool::set($schoolB);
    Grade::factory()->for($schoolB)->create(['name' => 'Class 1', 'sort_order' => 1]);

    CurrentSchool::set($schoolA);
    $results = Grade::all();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->id)->toBe($gradeA->id);
});

it('fails closed when querying grades without a current school', function () {
    CurrentSchool::forget();

    expect(fn () => Grade::count())->toThrow(NoCurrentSchoolException::class);
});

it('auto-fills school_id when creating a grade in a tenant context', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $grade = Grade::create([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ]);

    expect($grade->school_id)->toBe($school->id);
});

it('refuses cross-school writes when another school is current', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);

    $grade = new Grade([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ]);
    $grade->school_id = $schoolB->id;

    expect(fn () => $grade->save())
        ->toThrow(LogicException::class, 'Cross-school write refused');
});

it('prevents changing school_id after a grade is created', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $grade = Grade::factory()->for($schoolA)->create();

    $grade->school_id = $schoolB->id;

    expect(fn () => $grade->save())
        ->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

it('rejects duplicate grade name or sort_order in the same school while allowing them in another school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $existing = Grade::factory()->for($schoolA)->create([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ]);

    $duplicateName = gradeValidator([
        'name' => 'Class 5',
        'short_name' => 'V',
        'sort_order' => 9,
    ]);
    expect($duplicateName->fails())->toBeTrue()
        ->and($duplicateName->errors()->has('name'))->toBeTrue();

    $duplicateSort = gradeValidator([
        'name' => 'Class 6',
        'short_name' => '6',
        'sort_order' => 8,
    ]);
    expect($duplicateSort->fails())->toBeTrue()
        ->and($duplicateSort->errors()->has('sort_order'))->toBeTrue();

    $editSelf = gradeValidator([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ], $existing->id);
    expect($editSelf->passes())->toBeTrue();

    CurrentSchool::set($schoolB);
    $otherSchoolSameValues = gradeValidator([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ]);
    expect($otherSchoolSameValues->passes())->toBeTrue();
});

it('enforces unique name and sort_order per school at the database level', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    Grade::factory()->for($school)->create([
        'name' => 'Nursery',
        'sort_order' => 1,
    ]);

    expect(fn () => Grade::factory()->for($school)->create([
        'name' => 'Nursery',
        'sort_order' => 2,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => Grade::factory()->for($school)->create([
        'name' => 'LKG',
        'sort_order' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects invalid grade names, short names, and out-of-range sort orders', function (array $overrides, string $errorField) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $validator = gradeValidator(array_merge([
        'name' => 'Class 1',
        'short_name' => '1',
        'sort_order' => 4,
    ], $overrides));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has($errorField))->toBeTrue();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name over 30 chars' => [['name' => str_repeat('a', 31)], 'name'],
    'short name over 10 chars' => [['short_name' => str_repeat('a', 11)], 'short_name'],
    'negative sort order' => [['sort_order' => -1], 'sort_order'],
    'sort order above 50' => [['sort_order' => 51], 'sort_order'],
    'decimal sort order' => [['sort_order' => 1.5], 'sort_order'],
]);

it('hides archived grades in the active scope and sorts by sort_order in ordered scope', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $second = Grade::factory()->for($school)->create(['name' => 'Class 2', 'sort_order' => 5]);
    $archived = Grade::factory()->for($school)->archived()->create(['name' => 'Pre-Nursery', 'sort_order' => 1]);
    $first = Grade::factory()->for($school)->create(['name' => 'Class 1', 'sort_order' => 4]);

    expect($archived->isArchived())->toBeTrue()
        ->and($first->isArchived())->toBeFalse();

    $activeOrderedIds = Grade::active()->ordered()->pluck('id')->all();

    expect($activeOrderedIds)->toBe([$first->id, $second->id]);
});

it('creates 15 standard grades idempotently and touches only the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::forget();
    expect(fn () => app(CreateStandardGrades::class)->handle())
        ->toThrow(NoCurrentSchoolException::class);

    CurrentSchool::set($schoolA);
    $created = app(CreateStandardGrades::class)->handle();

    expect($created)->toHaveCount(15)
        ->and(Grade::count())->toBe(15)
        ->and(Grade::ordered()->pluck('name')->first())->toBe('Nursery')
        ->and(Grade::ordered()->pluck('name')->last())->toBe('Class 12')
        ->and(Grade::ordered()->pluck('sort_order')->all())->toBe(range(1, 15));

    $secondRun = app(CreateStandardGrades::class)->handle();

    expect($secondRun)->toHaveCount(0)
        ->and(Grade::count())->toBe(15);

    CurrentSchool::set($schoolB);
    expect(Grade::count())->toBe(0);
});

it('protects school_id and archived_at against mass assignment', function (string $attribute, mixed $value) {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    expect(fn () => Grade::create([
        'name' => 'Class 1',
        'short_name' => '1',
        'sort_order' => 4,
        $attribute => $value,
    ]))->toThrow(MassAssignmentException::class);
})->with([
    'school_id' => ['school_id', '01HZX9Y8B7C6D5E4F3G2H1J0K0'],
    'archived_at' => ['archived_at', '2026-11-26 00:00:00'],
]);

it('logs renaming a grade to the activity log with school_id and only allowed fields', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class One',
        'short_name' => '1',
        'sort_order' => 4,
    ]);

    $grade->update([
        'name' => 'Class 1',
    ]);

    /** @var Activity|null $activity */
    $activity = Activity::where('log_name', 'grade')
        ->where('event', 'updated')
        ->where('subject_id', $grade->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBe($school->id);

    $changes = $activity->attribute_changes?->toArray() ?? [];
    expect($changes['attributes'] ?? [])->toBe(['name' => 'Class 1'])
        ->and($changes['old'] ?? [])->toBe(['name' => 'Class One']);
});
