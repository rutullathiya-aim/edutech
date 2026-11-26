<?php

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
use App\Exceptions\NoCurrentSchoolException;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    CurrentSchool::forget();
});

test('a mismatched year and section enrollment is rejected by the composite foreign key', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $yearA = AcademicYear::factory()->for($school)->create([
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
        'is_current' => false,
    ]);
    $yearB = AcademicYear::factory()->for($school)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $grade = Grade::factory()->for($school)->create();
    $sectionInYearB = Section::factory()->for($school)->for($yearB)->for($grade)->create();
    $student = Student::factory()->for($school)->create();

    expect(fn () => Enrollment::create([
        'student_id' => $student->id,
        'academic_year_id' => $yearA->id,
        'section_id' => $sectionInYearB->id,
        'enrolled_on' => '2026-04-01',
    ]))->toThrow(QueryException::class);
});

test('cross-school foreign keys are rejected for enrollments and guardian links', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $yearA = AcademicYear::factory()->for($schoolA)->current()->create();
    $gradeA = Grade::factory()->for($schoolA)->create();
    $sectionA = Section::factory()->for($schoolA)->for($yearA)->for($gradeA)->create();
    $studentA = Student::factory()->for($schoolA)->create();
    $guardianA = Guardian::factory()->for($schoolA)->create();

    $yearB = AcademicYear::factory()->for($schoolB)->current()->create();
    $gradeB = Grade::factory()->for($schoolB)->create();
    $sectionB = Section::factory()->for($schoolB)->for($yearB)->for($gradeB)->create();
    $studentB = Student::factory()->for($schoolB)->create();
    $guardianB = Guardian::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);

    expect(fn () => Enrollment::create([
        'student_id' => $studentB->id,
        'academic_year_id' => $yearA->id,
        'section_id' => $sectionA->id,
        'enrolled_on' => '2026-04-01',
    ]))->toThrow(QueryException::class);

    expect(fn () => Enrollment::create([
        'student_id' => $studentA->id,
        'academic_year_id' => $yearB->id,
        'section_id' => $sectionB->id,
        'enrolled_on' => '2026-04-01',
    ]))->toThrow(QueryException::class);

    expect(fn () => StudentGuardian::create([
        'student_id' => $studentA->id,
        'guardian_id' => $guardianB->id,
        'relationship' => GuardianRelationship::Father,
        'is_primary' => true,
    ]))->toThrow(QueryException::class);

    expect(fn () => StudentGuardian::create([
        'student_id' => $studentB->id,
        'guardian_id' => $guardianA->id,
        'relationship' => GuardianRelationship::Mother,
        'is_primary' => false,
    ]))->toThrow(QueryException::class);
});

test('a student can have only one enrollment per academic year while roll numbers enforce section uniqueness and allow nulls', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year1 = AcademicYear::factory()->for($school)->create([
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
        'is_current' => false,
    ]);
    $year2 = AcademicYear::factory()->for($school)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $grade = Grade::factory()->for($school)->create();
    $section1A = Section::factory()->for($school)->for($year1)->for($grade)->create(['name' => 'A']);
    $section1B = Section::factory()->for($school)->for($year1)->for($grade)->create(['name' => 'B']);
    $section2A = Section::factory()->for($school)->for($year2)->for($grade)->create(['name' => 'A']);

    $student1 = Student::factory()->for($school)->create();
    $student2 = Student::factory()->for($school)->create();
    $student3 = Student::factory()->for($school)->create();

    Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $year1->id,
        'section_id' => $section1A->id,
        'roll_number' => 1,
        'enrolled_on' => '2025-04-01',
    ]);

    expect(fn () => Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $year1->id,
        'section_id' => $section1B->id,
        'roll_number' => 1,
        'enrolled_on' => '2025-04-01',
    ]))->toThrow(QueryException::class);

    // Same student in a second academic year succeeds.
    $nextYearEnrollment = Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $year2->id,
        'section_id' => $section2A->id,
        'roll_number' => 1,
        'enrolled_on' => '2026-04-01',
    ]);

    // Duplicate roll number in the same section is rejected.
    expect(fn () => Enrollment::create([
        'student_id' => $student2->id,
        'academic_year_id' => $year1->id,
        'section_id' => $section1A->id,
        'roll_number' => 1,
        'enrolled_on' => '2025-04-01',
    ]))->toThrow(QueryException::class);

    // Multiple null roll numbers in the same section and same roll number in another section succeed.
    $nullRollTwo = Enrollment::create([
        'student_id' => $student2->id,
        'academic_year_id' => $year1->id,
        'section_id' => $section1A->id,
        'roll_number' => null,
        'enrolled_on' => '2025-04-01',
    ]);
    $nullRollThree = Enrollment::create([
        'student_id' => $student3->id,
        'academic_year_id' => $year1->id,
        'section_id' => $section1A->id,
        'roll_number' => null,
        'enrolled_on' => '2025-04-01',
    ]);

    expect($nextYearEnrollment->roll_number)->toBe(1)
        ->and($nullRollTwo->roll_number)->toBeNull()
        ->and($nullRollThree->roll_number)->toBeNull();
});

test('a student can have at most one primary guardian via generated flag and guardians cannot link twice', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $student = Student::factory()->for($school)->create();
    $father = Guardian::factory()->for($school)->create();
    $mother = Guardian::factory()->for($school)->create();
    $grandparent = Guardian::factory()->for($school)->create();

    $primaryLink = StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $father->id,
        'relationship' => GuardianRelationship::Father,
        'is_primary' => true,
    ]);

    expect(fn () => StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $mother->id,
        'relationship' => GuardianRelationship::Mother,
        'is_primary' => true,
    ]))->toThrow(QueryException::class);

    $motherLink = StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $mother->id,
        'relationship' => GuardianRelationship::Mother,
        'is_primary' => false,
    ]);
    StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $grandparent->id,
        'relationship' => GuardianRelationship::Grandparent,
        'is_primary' => false,
    ]);

    // Same student + guardian cannot be linked twice.
    expect(fn () => StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $mother->id,
        'relationship' => GuardianRelationship::Guardian,
        'is_primary' => false,
    ]))->toThrow(QueryException::class);

    // Changing who an existing StudentGuardian links is forbidden.
    $secondStudent = Student::factory()->for($school)->create();
    expect(fn () => $motherLink->update(['student_id' => $secondStudent->id]))->toThrow(LogicException::class);
    expect(fn () => $motherLink->fresh()?->update(['guardian_id' => $grandparent->id]))->toThrow(LogicException::class);

    // Switching primary guardian after clearing the current primary succeeds.
    $primaryLink->update(['is_primary' => false]);
    $motherLink->refresh()->update(['is_primary' => true]);

    expect($primaryLink->fresh()?->isPrimary())->toBeFalse()
        ->and($motherLink->fresh()?->isPrimary())->toBeTrue();
});

test('admission numbers and non-null apaar ids are unique per school while guardian mobiles are non-unique', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);

    Student::factory()->for($schoolA)->create([
        'admission_no' => 'ADM-001',
        'apaar_id' => '123456789012',
    ]);

    expect(fn () => Student::factory()->for($schoolA)->create([
        'admission_no' => 'ADM-001',
    ]))->toThrow(QueryException::class);

    expect(fn () => Student::factory()->for($schoolA)->create([
        'admission_no' => 'ADM-002',
        'apaar_id' => '123456789012',
    ]))->toThrow(QueryException::class);

    // Multiple null or whitespace-only APAAR IDs are allowed in the same school.
    $nullApaarOne = Student::factory()->for($schoolA)->create([
        'admission_no' => 'ADM-002',
        'apaar_id' => null,
    ]);
    $nullApaarTwo = Student::factory()->for($schoolA)->create([
        'admission_no' => 'ADM-003',
        'apaar_id' => '   ',
    ]);

    // Guardians in the same school may share one mobile phone.
    $father = Guardian::factory()->for($schoolA)->create(['mobile' => '9876543210']);
    $mother = Guardian::factory()->for($schoolA)->create(['mobile' => '9876543210']);

    expect($nullApaarOne->apaar_id)->toBeNull()
        ->and($nullApaarTwo->apaar_id)->toBeNull()
        ->and($father->mobile)->toBe('+919876543210')
        ->and($mother->mobile)->toBe('+919876543210');

    CurrentSchool::set($schoolB);

    $otherSchoolStudent = Student::factory()->for($schoolB)->create([
        'admission_no' => 'ADM-001',
        'apaar_id' => '123456789012',
    ]);

    expect($otherSchoolStudent->admission_no)->toBe('ADM-001');
});

test('enrollments reject closed academic years, archived grades, and mutating student or year while allowing section transfers', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $openYear = AcademicYear::factory()->for($school)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $otherOpenYear = AcademicYear::factory()->for($school)->create([
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
        'is_current' => false,
    ]);

    $activeGrade = Grade::factory()->for($school)->create();
    $archivableGrade = Grade::factory()->for($school)->create();

    $sectionA = Section::factory()->for($school)->for($openYear)->for($activeGrade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($openYear)->for($activeGrade)->create(['name' => 'B']);
    $archivedSection = Section::factory()->for($school)->for($openYear)->for($archivableGrade)->create(['name' => 'A']);
    $archivableGrade->forceFill(['archived_at' => Carbon::now()])->save();

    // Create section and initial enrollment before closing the academic year.
    $closableYear = AcademicYear::factory()->for($school)->create([
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
        'is_current' => false,
    ]);
    $sectionInClosableYear = Section::factory()->for($school)->for($closableYear)->for($activeGrade)->create(['name' => 'A']);
    $preClosedEnrollment = Enrollment::factory()->forSection($sectionInClosableYear)->create();
    $closableYear->forceFill(['closed_at' => Carbon::now()])->save();

    $student1 = Student::factory()->for($school)->create();
    $student2 = Student::factory()->for($school)->create();

    expect(fn () => Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $closableYear->id,
        'section_id' => $sectionInClosableYear->id,
        'enrolled_on' => '2025-04-01',
    ]))->toThrow(LogicException::class);

    expect(fn () => $preClosedEnrollment->update(['roll_number' => 12]))
        ->toThrow(LogicException::class);

    expect(fn () => Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $openYear->id,
        'section_id' => $archivedSection->id,
        'enrolled_on' => '2026-04-01',
    ]))->toThrow(LogicException::class);

    $enrollment = Enrollment::create([
        'student_id' => $student1->id,
        'academic_year_id' => $openYear->id,
        'section_id' => $sectionA->id,
        'enrolled_on' => '2026-04-01',
    ]);

    expect(fn () => $enrollment->update(['student_id' => $student2->id]))
        ->toThrow(LogicException::class);

    expect(fn () => $enrollment->fresh()?->update(['academic_year_id' => $otherOpenYear->id]))
        ->toThrow(LogicException::class);

    $enrollment->fresh()?->update(['section_id' => $sectionB->id]);

    expect($enrollment->fresh()?->section_id)->toBe($sectionB->id);
});

test('foreign key restrictions prevent deleting referenced students, guardians, sections, and academic years', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->create(['is_current' => false]);
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create();
    $student = Student::factory()->for($school)->create();
    $guardian = Guardian::factory()->for($school)->create();

    Enrollment::create([
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
        'section_id' => $section->id,
        'enrolled_on' => '2026-04-01',
    ]);
    StudentGuardian::create([
        'student_id' => $student->id,
        'guardian_id' => $guardian->id,
        'relationship' => GuardianRelationship::Father,
        'is_primary' => true,
    ]);

    expect(fn () => $student->delete())->toThrow(QueryException::class);
    expect(fn () => $guardian->delete())->toThrow(QueryException::class);
    expect(fn () => $section->delete())->toThrow(QueryException::class);
    expect(fn () => $year->delete())->toThrow(QueryException::class);
});

test('models enforce school isolation, immutable school_id, and guarded attributes against mass assignment', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);
    $studentA = Student::factory()->for($schoolA)->create();
    $guardianA = Guardian::factory()->for($schoolA)->create();
    $enrollmentA = Enrollment::factory()->for($schoolA)->for($studentA)->create();
    StudentGuardian::factory()->for($schoolA)->for($studentA)->for($guardianA)->primary()->create();

    CurrentSchool::set($schoolB);
    Student::factory()->for($schoolB)->create();

    expect(Student::query()->count())->toBe(1)
        ->and(Guardian::query()->count())->toBe(0)
        ->and(Enrollment::query()->count())->toBe(0)
        ->and(StudentGuardian::query()->count())->toBe(0);

    CurrentSchool::forget();

    expect(fn () => Student::query()->count())->toThrow(NoCurrentSchoolException::class);
    expect(fn () => Guardian::query()->count())->toThrow(NoCurrentSchoolException::class);
    expect(fn () => Enrollment::query()->count())->toThrow(NoCurrentSchoolException::class);
    expect(fn () => StudentGuardian::query()->count())->toThrow(NoCurrentSchoolException::class);

    expect(fn () => $studentA->update(['school_id' => $schoolB->id]))->toThrow(MassAssignmentException::class);

    $studentA->school_id = $schoolB->id;
    expect(fn () => $studentA->save())->toThrow(LogicException::class);

    expect(fn () => $studentA->update(['status' => StudentStatus::Left]))->toThrow(MassAssignmentException::class);
    expect(fn () => $studentA->update(['consent_recorded_at' => Carbon::now()]))->toThrow(MassAssignmentException::class);
    expect(fn () => $studentA->update(['consent_recorded_by' => User::factory()->create()->id]))->toThrow(MassAssignmentException::class);
    expect(fn () => $guardianA->update(['school_id' => $schoolB->id]))->toThrow(MassAssignmentException::class);
    expect(fn () => $enrollmentA->update(['school_id' => $schoolB->id]))->toThrow(MassAssignmentException::class);
    expect(fn () => StudentGuardian::create(['school_id' => $schoolA->id, 'student_id' => $studentA->id, 'guardian_id' => $guardianA->id]))
        ->toThrow(MassAssignmentException::class);
    expect(fn () => StudentGuardian::create(['primary_flag' => 1, 'student_id' => $studentA->id, 'guardian_id' => $guardianA->id]))
        ->toThrow(MassAssignmentException::class);
});

test('students and guardians trim text, normalize contacts, cast enums, and use school context on creation', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    $student = Student::create([
        'admission_no' => '  ADM-2026-0099  ',
        'admission_date' => '2026-04-01',
        'name' => '  Aarav Verma  ',
        'date_of_birth' => '2015-08-14',
        'gender' => Gender::Male,
        'social_category' => SocialCategory::Obc,
        'religion' => '   ',
        'nationality' => '   ',
        'blood_group' => BloodGroup::OPos,
        'state' => 'Maharashtra',
        'previous_school' => '  Sunrise Public School  ',
        'pincode' => '  400001  ',
    ]);

    $blankStateStudent = Student::factory()->for($school)->create([
        'state' => '   ',
    ]);

    $guardian = Guardian::create([
        'name' => '  Rohan Verma  ',
        'mobile' => '09876543210',
        'email' => '  Rohan.Verma@Example.COM  ',
        'occupation' => '   ',
    ]);

    expect($student->school_id)->toBe($school->id)
        ->and($student->admission_no)->toBe('ADM-2026-0099')
        ->and($student->name)->toBe('Aarav Verma')
        ->and($student->nationality)->toBe('Indian')
        ->and($student->religion)->toBeNull()
        ->and($student->state)->toBe(IndianState::Maharashtra)
        ->and($blankStateStudent->state)->toBeNull()
        ->and($student->previous_school)->toBe('Sunrise Public School')
        ->and($student->pincode)->toBe('400001')
        ->and($student->status)->toBe(StudentStatus::Active)
        ->and($student->gender)->toBe(Gender::Male)
        ->and($student->social_category)->toBe(SocialCategory::Obc)
        ->and($student->blood_group)->toBe(BloodGroup::OPos);

    expect($guardian->school_id)->toBe($school->id)
        ->and($guardian->name)->toBe('Rohan Verma')
        ->and($guardian->mobile)->toBe('+919876543210')
        ->and($guardian->email)->toBe('rohan.verma@example.com')
        ->and($guardian->occupation)->toBeNull();
});

test('student activity log never records sensitive values and logs only changed sensitive field names', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();
    CurrentSchool::set($school);
    $this->actingAs($user);

    $student = Student::create([
        'admission_no' => 'ADM-AUDIT-01',
        'admission_date' => '2026-04-01',
        'name' => 'Diya Nair',
        'date_of_birth' => '2014-03-19',
        'gender' => Gender::Female,
        'social_category' => SocialCategory::Sc,
        'religion' => 'Hindu',
        'apaar_id' => '987654321098',
        'pen' => '12345678901',
        'aadhaar_last4' => '7788',
    ]);

    $createLog = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->where('event', 'created')
        ->firstOrFail();

    $createPayload = json_encode($createLog->toArray()) ?: '';

    expect($createPayload)
        ->not->toContain('2014-03-19')
        ->not->toContain('"sc"')
        ->not->toContain('Hindu')
        ->not->toContain('987654321098')
        ->not->toContain('12345678901')
        ->not->toContain('7788')
        ->and($createLog->attribute_changes?->toArray()['attributes'] ?? [])
        ->not->toHaveKeys(Student::SENSITIVE_AUDIT_FIELDS)
        ->and($createLog->getProperty('sensitive_fields_changed'))
        ->toMatchArray(['social_category', 'religion', 'aadhaar_last4', 'date_of_birth', 'apaar_id', 'pen']);

    // Sensitive-only update logs exactly one activity containing field name only.
    $student->update(['religion' => 'Christian', 'social_category' => SocialCategory::Obc]);

    $sensitiveOnlyLog = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    $sensitiveOnlyPayload = json_encode($sensitiveOnlyLog->toArray()) ?: '';

    expect($sensitiveOnlyPayload)
        ->not->toContain('Hindu')
        ->not->toContain('Christian')
        ->not->toContain('"sc"')
        ->not->toContain('"obc"')
        ->and($sensitiveOnlyLog->getProperty('sensitive_fields_changed'))
        ->toBe(['social_category', 'religion']);

    // Mixed update logs non-sensitive diff and sensitive field name without sensitive value.
    $student->update([
        'name' => 'Diya S. Nair',
        'aadhaar_last4' => '4455',
    ]);

    $mixedLog = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    $mixedPayload = json_encode($mixedLog->toArray()) ?: '';

    expect($mixedPayload)
        ->not->toContain('7788')
        ->not->toContain('4455')
        ->and($mixedLog->attribute_changes?->toArray()['attributes']['name'] ?? null)->toBe('Diya S. Nair')
        ->and($mixedLog->getProperty('sensitive_fields_changed'))->toBe(['aadhaar_last4']);

    // Touch produces zero new audit entries.
    $countBeforeTouch = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->count();

    $student->touch();

    expect(Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->count())->toBe($countBeforeTouch);
});
