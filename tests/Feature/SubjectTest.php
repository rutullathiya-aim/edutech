<?php

use App\Actions\Subjects\CreateStandardSubjects;
use App\Actions\Subjects\SyncGradeSubjects;
use App\Concerns\GradeSubjectValidationRules;
use App\Concerns\SubjectValidationRules;
use App\Enums\SubjectKind;
use App\Exceptions\NoCurrentSchoolException;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\School;
use App\Models\Subject;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Helper to expose SubjectValidationRules for feature tests.
 *
 * @param  array<string, mixed>  $data
 */
function subjectValidator(array $data, ?string $subjectId = null): Illuminate\Validation\Validator
{
    $provider = new class
    {
        use SubjectValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(?string $id = null): array
        {
            return $this->subjectRules($id);
        }
    };

    return Validator::make($data, $provider->rules($subjectId));
}

/**
 * Helper to expose GradeSubjectValidationRules for feature tests.
 *
 * @param  array<string, mixed>  $data
 */
function curriculumValidator(array $data): Illuminate\Validation\Validator
{
    $provider = new class
    {
        use GradeSubjectValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(): array
        {
            return $this->gradeSubjectSyncRules();
        }
    };

    return Validator::make($data, $provider->rules());
}

it('creates Subject and GradeSubject records with 26-character ULIDs and consistent school ownership', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $subject = Subject::factory()->for($school)->create([
        'name' => 'Mathematics',
        'code' => '041',
        'kind' => SubjectKind::Scholastic,
    ]);
    $gradeSubject = GradeSubject::factory()->recycle($school)->create();

    expect($subject->id)->toBeString()
        ->and(strlen($subject->id))->toBe(26)
        ->and(Str::isUlid($subject->id))->toBeTrue()
        ->and($subject->kind)->toBe(SubjectKind::Scholastic)
        ->and($gradeSubject->id)->toBeString()
        ->and(strlen($gradeSubject->id))->toBe(26)
        ->and(Str::isUlid($gradeSubject->id))->toBeTrue()
        ->and($gradeSubject->school_id)->toBe($school->id)
        ->and($gradeSubject->grade->school_id)->toBe($school->id)
        ->and($gradeSubject->subject->school_id)->toBe($school->id);
});

it('isolates subjects and grade subjects to the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $subjectA = Subject::factory()->for($schoolA)->create(['name' => 'Mathematics', 'code' => '041']);
    $gradeSubjectA = GradeSubject::factory()->recycle([$schoolA, $subjectA])->create();

    CurrentSchool::set($schoolB);
    $subjectB = Subject::factory()->for($schoolB)->create(['name' => 'Mathematics', 'code' => '041']);
    GradeSubject::factory()->recycle([$schoolB, $subjectB])->create();

    CurrentSchool::set($schoolA);

    expect(Subject::pluck('id')->all())->toBe([$subjectA->id])
        ->and(GradeSubject::pluck('id')->all())->toBe([$gradeSubjectA->id]);
});

it('fails closed when querying Subject or GradeSubject without a current school', function () {
    CurrentSchool::forget();

    expect(fn () => Subject::count())->toThrow(NoCurrentSchoolException::class)
        ->and(fn () => GradeSubject::count())->toThrow(NoCurrentSchoolException::class);
});

it('auto-fills school_id, blocks cross-school writes, and prevents changing school_id for both models', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolB);
    $gradeB = Grade::factory()->for($schoolB)->create();
    $subjectB = Subject::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    $gradeA = Grade::factory()->for($schoolA)->create();
    $subjectA = Subject::create([
        'name' => 'Physics',
        'code' => '042',
        'kind' => SubjectKind::Scholastic,
    ]);
    $gradeSubjectA = GradeSubject::create([
        'grade_id' => $gradeA->id,
        'subject_id' => $subjectA->id,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    expect($subjectA->school_id)->toBe($schoolA->id)
        ->and($gradeSubjectA->school_id)->toBe($schoolA->id);

    $foreignSubject = new Subject(['name' => 'Art', 'kind' => SubjectKind::CoScholastic]);
    $foreignSubject->school_id = $schoolB->id;
    expect(fn () => $foreignSubject->save())
        ->toThrow(LogicException::class, 'Cross-school write refused');

    $foreignGs = new GradeSubject([
        'grade_id' => $gradeB->id,
        'subject_id' => $subjectB->id,
        'sort_order' => 1,
    ]);
    $foreignGs->school_id = $schoolB->id;
    expect(fn () => $foreignGs->save())
        ->toThrow(LogicException::class, 'Cross-school write refused');

    $subjectA->school_id = $schoolB->id;
    expect(fn () => $subjectA->save())
        ->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');

    $gradeSubjectA->school_id = $schoolB->id;
    expect(fn () => $gradeSubjectA->save())
        ->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

it('enforces composite foreign keys at the database level when assigning another schools grade or subject', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $gradeA = Grade::factory()->for($schoolA)->create();
    $subjectA = Subject::factory()->for($schoolA)->create();

    CurrentSchool::set($schoolB);
    $gradeB = Grade::factory()->for($schoolB)->create();
    $subjectB = Subject::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    expect(fn () => GradeSubject::create([
        'grade_id' => $gradeB->id,
        'subject_id' => $subjectA->id,
        'sort_order' => 1,
    ]))->toThrow(QueryException::class);

    expect(fn () => GradeSubject::create([
        'grade_id' => $gradeA->id,
        'subject_id' => $subjectB->id,
        'sort_order' => 1,
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate subject names or codes within one school while allowing multiple null codes and duplicates across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $math = Subject::factory()->for($schoolA)->create([
        'name' => 'Mathematics',
        'code' => '041',
    ]);
    // Multiple subjects without code (null) are allowed in same school
    Subject::factory()->for($schoolA)->create(['name' => 'Art Education', 'code' => null]);
    Subject::factory()->for($schoolA)->create(['name' => 'Work Education', 'code' => '   ']);

    expect(subjectValidator(['name' => 'Mathematics', 'code' => '099', 'kind' => SubjectKind::Scholastic->value])->fails())->toBeTrue()
        ->and(subjectValidator(['name' => 'Math Alt', 'code' => '041', 'kind' => SubjectKind::Scholastic->value])->fails())->toBeTrue()
        ->and(subjectValidator(['name' => 'Mathematics', 'code' => '041', 'kind' => SubjectKind::Scholastic->value], $math->id)->passes())->toBeTrue();

    expect(fn () => Subject::factory()->for($schoolA)->create(['name' => 'Mathematics', 'code' => '999']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => Subject::factory()->for($schoolA)->create(['name' => 'Another Math', 'code' => '041']))
        ->toThrow(UniqueConstraintViolationException::class);

    CurrentSchool::set($schoolB);
    expect(subjectValidator(['name' => 'Mathematics', 'code' => '041', 'kind' => SubjectKind::Scholastic->value])->passes())->toBeTrue();

    $otherMath = Subject::factory()->for($schoolB)->create(['name' => 'Mathematics', 'code' => '041']);
    expect($otherMath->name)->toBe('Mathematics');
});

it('prevents assigning the same subject twice to the same grade at the database level', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $grade = Grade::factory()->for($school)->create();
    $subject = Subject::factory()->for($school)->create();

    GradeSubject::create([
        'grade_id' => $grade->id,
        'subject_id' => $subject->id,
        'sort_order' => 1,
    ]);

    expect(fn () => GradeSubject::create([
        'grade_id' => $grade->id,
        'subject_id' => $subject->id,
        'sort_order' => 2,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('replaces a grades subject curriculum atomically and rolls back on invalid subjects', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolB);
    $foreignSubject = Subject::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);
    $grade = Grade::factory()->for($schoolA)->create();
    $english = Subject::factory()->for($schoolA)->create(['name' => 'English']);
    $math = Subject::factory()->for($schoolA)->create(['name' => 'Mathematics']);
    $sanskrit = Subject::factory()->for($schoolA)->create(['name' => 'Sanskrit']);
    $archivedSubject = Subject::factory()->for($schoolA)->archived()->create(['name' => 'Legacy Typing']);

    $sync = app(SyncGradeSubjects::class);

    $synced = $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $math->id, 'is_optional' => false, 'sort_order' => 2],
    ]);

    expect($synced)->toHaveCount(2)
        ->and(GradeSubject::where('grade_id', $grade->id)->count())->toBe(2);

    // Replace with updated set
    $sync->handle($grade, [
        ['subject_id' => $math->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $sanskrit->id, 'is_optional' => true, 'sort_order' => 2],
    ]);

    $rows = GradeSubject::where('grade_id', $grade->id)->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('subject_id')->all())->toBe([$math->id, $sanskrit->id])
        ->and($rows[1]->is_optional)->toBeTrue();

    // One archived subject among valid ones fails atomically and preserves previous state
    expect(fn () => $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $archivedSubject->id, 'is_optional' => false, 'sort_order' => 2],
    ]))->toThrow(LogicException::class, 'Cannot assign archived subject');

    expect(GradeSubject::where('grade_id', $grade->id)->orderBy('sort_order')->pluck('subject_id')->all())
        ->toBe([$math->id, $sanskrit->id]);

    // One foreign-school subject fails atomically and preserves previous state
    expect(fn () => $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $foreignSubject->id, 'is_optional' => false, 'sort_order' => 2],
    ]))->toThrow(LogicException::class, 'Subject does not belong to the current school or does not exist.');

    expect(GradeSubject::where('grade_id', $grade->id)->orderBy('sort_order')->pluck('subject_id')->all())
        ->toBe([$math->id, $sanskrit->id]);
});

it('validates curriculum sync rules for foreign and archived grades and subjects', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolB);
    $foreignGrade = Grade::factory()->for($schoolB)->create();
    $foreignSubject = Subject::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);
    $activeGrade = Grade::factory()->for($schoolA)->create();
    $archivedGrade = Grade::factory()->for($schoolA)->archived()->create();
    $activeSubject = Subject::factory()->for($schoolA)->create();
    $archivedSubject = Subject::factory()->for($schoolA)->archived()->create();

    expect(curriculumValidator([
        'grade_id' => $foreignGrade->id,
        'items' => [['subject_id' => $activeSubject->id, 'is_optional' => false, 'sort_order' => 1]],
    ])->fails())->toBeTrue();

    expect(curriculumValidator([
        'grade_id' => $archivedGrade->id,
        'items' => [['subject_id' => $activeSubject->id, 'is_optional' => false, 'sort_order' => 1]],
    ])->fails())->toBeTrue();

    expect(curriculumValidator([
        'grade_id' => $activeGrade->id,
        'items' => [['subject_id' => $foreignSubject->id, 'is_optional' => false, 'sort_order' => 1]],
    ])->fails())->toBeTrue();

    expect(curriculumValidator([
        'grade_id' => $activeGrade->id,
        'items' => [['subject_id' => $archivedSubject->id, 'is_optional' => false, 'sort_order' => 1]],
    ])->fails())->toBeTrue();
});

it('creates 17 standard scholastic and co-scholastic subjects idempotently scoped to the current school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::forget();
    expect(fn () => app(CreateStandardSubjects::class)->handle())
        ->toThrow(NoCurrentSchoolException::class);

    CurrentSchool::set($schoolA);
    $created = app(CreateStandardSubjects::class)->handle();

    expect($created)->toHaveCount(17)
        ->and(Subject::count())->toBe(17)
        ->and(Subject::where('kind', SubjectKind::Scholastic)->count())->toBe(14)
        ->and(Subject::where('kind', SubjectKind::CoScholastic)->count())->toBe(3);

    $secondRun = app(CreateStandardSubjects::class)->handle();
    expect($secondRun)->toHaveCount(0)
        ->and(Subject::count())->toBe(17);

    CurrentSchool::set($schoolB);
    expect(Subject::count())->toBe(0);
});

it('protects school_id and archived_at on Subject and school_id on GradeSubject from mass assignment and records audit entries', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    expect(fn () => Subject::create([
        'school_id' => $school->id,
        'name' => 'Math',
        'kind' => SubjectKind::Scholastic,
    ]))->toThrow(MassAssignmentException::class);

    expect(fn () => Subject::create([
        'archived_at' => now(),
        'name' => 'Math',
        'kind' => SubjectKind::Scholastic,
    ]))->toThrow(MassAssignmentException::class);

    $grade = Grade::factory()->for($school)->create();
    $subject = Subject::factory()->for($school)->create(['name' => 'History']);

    expect(fn () => GradeSubject::create([
        'school_id' => $school->id,
        'grade_id' => $grade->id,
        'subject_id' => $subject->id,
        'sort_order' => 1,
    ]))->toThrow(MassAssignmentException::class);

    $subject->update(['name' => 'World History', 'code' => '027']);

    $audit = Activity::where('log_name', 'subject')
        ->where('event', 'updated')
        ->where('subject_id', $subject->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->school_id)->toBe($school->id);
});

it('orders scholastic subjects alphabetically before co-scholastic subjects', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    Subject::factory()->for($school)->coScholastic()->create(['name' => 'Art Education']);
    Subject::factory()->for($school)->create(['name' => 'Science', 'kind' => SubjectKind::Scholastic]);
    Subject::factory()->for($school)->create(['name' => 'English', 'kind' => SubjectKind::Scholastic]);
    Subject::factory()->for($school)->coScholastic()->create(['name' => 'Work Education']);

    expect(Subject::ordered()->pluck('name')->all())->toBe([
        'English',
        'Science',
        'Art Education',
        'Work Education',
    ]);
});

it('preserves grade subject IDs on repeated syncs and updates or deletes only changed rows', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $grade = Grade::factory()->for($school)->create();
    $english = Subject::factory()->for($school)->create(['name' => 'English']);
    $math = Subject::factory()->for($school)->create(['name' => 'Mathematics']);
    $sanskrit = Subject::factory()->for($school)->create(['name' => 'Sanskrit']);

    $sync = app(SyncGradeSubjects::class);

    $initial = $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $math->id, 'is_optional' => false, 'sort_order' => 2],
        ['subject_id' => $sanskrit->id, 'is_optional' => false, 'sort_order' => 3],
    ])->keyBy('subject_id');

    $englishRowId = $initial->get($english->id)?->id;
    $mathRowId = $initial->get($math->id)?->id;
    $sanskritRowId = $initial->get($sanskrit->id)?->id;

    $auditCountAfterInitial = Activity::where('log_name', 'grade_subject')->count();

    // Saving the same set twice keeps all existing IDs and adds zero audit entries
    $second = $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $math->id, 'is_optional' => false, 'sort_order' => 2],
        ['subject_id' => $sanskrit->id, 'is_optional' => false, 'sort_order' => 3],
    ])->keyBy('subject_id');

    expect($second->get($english->id)?->id)->toBe($englishRowId)
        ->and($second->get($math->id)?->id)->toBe($mathRowId)
        ->and($second->get($sanskrit->id)?->id)->toBe($sanskritRowId)
        ->and(Activity::where('log_name', 'grade_subject')->count())->toBe($auditCountAfterInitial);

    // Changing only is_optional on Sanskrit updates that one row in place and logs one updated entry
    $third = $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $math->id, 'is_optional' => false, 'sort_order' => 2],
        ['subject_id' => $sanskrit->id, 'is_optional' => true, 'sort_order' => 3],
    ])->keyBy('subject_id');

    $updateAudit = Activity::where('log_name', 'grade_subject')
        ->where('event', 'updated')
        ->where('subject_id', $sanskritRowId)
        ->first();

    expect($third->get($sanskrit->id)?->id)->toBe($sanskritRowId)
        ->and($third->get($sanskrit->id)?->is_optional)->toBeTrue()
        ->and(Activity::where('log_name', 'grade_subject')->count())->toBe($auditCountAfterInitial + 1)
        ->and($updateAudit)->not->toBeNull()
        ->and($updateAudit?->subject_id)->toBe($sanskritRowId);

    // Removing Mathematics deletes only that row while keeping English and Sanskrit IDs intact
    $fourth = $sync->handle($grade, [
        ['subject_id' => $english->id, 'is_optional' => false, 'sort_order' => 1],
        ['subject_id' => $sanskrit->id, 'is_optional' => true, 'sort_order' => 2],
    ])->keyBy('subject_id');

    expect($fourth)->toHaveCount(2)
        ->and($fourth->get($english->id)?->id)->toBe($englishRowId)
        ->and($fourth->get($sanskrit->id)?->id)->toBe($sanskritRowId)
        ->and(GradeSubject::find($mathRowId))->toBeNull();
});
