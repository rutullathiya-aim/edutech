<?php

use App\Actions\Students\AdmitStudent;
use App\Actions\Students\AttachGuardian;
use App\Actions\Students\ChangeSection;
use App\Actions\Students\DetachGuardian;
use App\Actions\Students\SetPrimaryGuardian;
use App\Actions\Students\SuggestAdmissionNumber;
use App\Actions\Students\UpdateStudent;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\SchoolRole;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
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
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    test()->seed(PermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    CurrentSchool::forget();
    setPermissionsTeamId(null);
});

/**
 * Helper to create a user with a given role scoped to the given school and return the user.
 */
function createStudentActionActor(School $school, SchoolRole $role): User
{
    $user = User::factory()->create();
    setPermissionsTeamId($school->id);
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');

    return $user;
}

/**
 * Valid admission payload helper keeping tests DRY while keeping overrides explicit.
 *
 * @return array<string, mixed>
 */
function validStudentPayload(array $overrides = []): array
{
    return array_merge([
        'admission_no' => 'SPS-0101',
        'admission_date' => '2026-04-01',
        'name' => 'Aarav Sharma',
        'date_of_birth' => '2016-06-15',
        'gender' => Gender::Male->value,
        'social_category' => SocialCategory::General->value,
        'religion' => 'Hindu',
        'nationality' => 'Indian',
        'mother_tongue' => 'Hindi',
        'blood_group' => BloodGroup::OPos->value,
        'apaar_id' => '123456789012',
        'pen' => '12345678901',
        'aadhaar_last4' => '4321',
        'address_line' => '12 MG Road',
        'city' => 'Ahmedabad',
        'state' => IndianState::Gujarat->value,
        'pincode' => '380001',
    ], $overrides);
}

test('policies allow Teacher and Accountant to view students without sensitive access and restrict manage or import to Owner and Admin', function (
    SchoolRole $role,
    bool $canView,
    bool $canManage,
    bool $canViewSensitive,
    bool $canImport,
) {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $user = createStudentActionActor($schoolA, $role);

    CurrentSchool::set($schoolB);
    $studentB = Student::factory()->for($schoolB)->create();
    $guardianB = Guardian::factory()->for($schoolB)->create();
    $enrollmentB = Enrollment::factory()->for($schoolB)->create();

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolA->id);
    $studentA = Student::factory()->for($schoolA)->create();
    $guardianA = Guardian::factory()->for($schoolA)->create();
    $enrollmentA = Enrollment::factory()->for($schoolA)->create();

    expect($user->can('viewAny', Student::class))->toBe($canView)
        ->and($user->can('view', $studentA))->toBe($canView)
        ->and($user->can('viewSensitive', Student::class))->toBe($canViewSensitive)
        ->and($user->can('viewSensitive', $studentA))->toBe($canViewSensitive)
        ->and($user->can('create', Student::class))->toBe($canManage)
        ->and($user->can('update', $studentA))->toBe($canManage)
        ->and($user->can('import', Student::class))->toBe($canImport)
        ->and($user->can('delete', $studentA))->toBeFalse()
        ->and($user->can('view', $studentB))->toBeFalse()
        ->and($user->can('viewSensitive', $studentB))->toBeFalse()
        ->and($user->can('update', $studentB))->toBeFalse();

    expect($user->can('viewAny', Guardian::class))->toBe($canView)
        ->and($user->can('view', $guardianA))->toBe($canView)
        ->and($user->can('create', Guardian::class))->toBe($canManage)
        ->and($user->can('update', $guardianA))->toBe($canManage)
        ->and($user->can('delete', $guardianA))->toBeFalse()
        ->and($user->can('view', $guardianB))->toBeFalse()
        ->and($user->can('update', $guardianB))->toBeFalse();

    expect($user->can('viewAny', Enrollment::class))->toBe($canView)
        ->and($user->can('view', $enrollmentA))->toBe($canView)
        ->and($user->can('create', Enrollment::class))->toBe($canManage)
        ->and($user->can('update', $enrollmentA))->toBe($canManage)
        ->and($user->can('delete', $enrollmentA))->toBeFalse()
        ->and($user->can('view', $enrollmentB))->toBeFalse()
        ->and($user->can('update', $enrollmentB))->toBeFalse();
})->with([
    'Owner' => [SchoolRole::Owner, true, true, true, true],
    'Admin' => [SchoolRole::Admin, true, true, true, true],
    'Accountant' => [SchoolRole::Accountant, true, false, false, false],
    'Teacher' => [SchoolRole::Teacher, true, false, false, false],
    'Parent' => [SchoolRole::Parent, false, false, false, false],
    'Student' => [SchoolRole::Student, false, false, false, false],
]);

test('policies fail closed when CurrentSchool is missing or permission team mismatches or user is non-member', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $owner = createStudentActionActor($schoolA, SchoolRole::Owner);
    $outsider = User::factory()->create();

    CurrentSchool::set($schoolA);
    $student = Student::factory()->for($schoolA)->create();
    $guardian = Guardian::factory()->for($schoolA)->create();
    $enrollment = Enrollment::factory()->for($schoolA)->create();

    setPermissionsTeamId($schoolA->id);
    expect($outsider->can('viewAny', Student::class))->toBeFalse()
        ->and($outsider->can('view', $student))->toBeFalse()
        ->and($outsider->can('view', $guardian))->toBeFalse()
        ->and($outsider->can('view', $enrollment))->toBeFalse();

    setPermissionsTeamId($schoolB->id);
    $owner->unsetRelation('roles', 'permissions');
    expect($owner->can('viewAny', Student::class))->toBeFalse()
        ->and($owner->can('viewSensitive', Student::class))->toBeFalse()
        ->and($owner->can('import', Student::class))->toBeFalse()
        ->and($owner->can('create', Student::class))->toBeFalse();

    CurrentSchool::forget();
    setPermissionsTeamId($schoolA->id);
    $owner->unsetRelation('roles', 'permissions');
    expect($owner->can('viewAny', Student::class))->toBeFalse()
        ->and($owner->can('view', $student))->toBeFalse()
        ->and($owner->can('update', $enrollment))->toBeFalse();
});

test('non-managing roles are forbidden from executing student, guardian, or enrollment mutation actions', function (SchoolRole $role) {
    $school = School::factory()->create();
    $actor = createStudentActionActor($school, $role);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $sectionA = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'B']);
    $student = Student::factory()->for($school)->create();
    $guardian = Guardian::factory()->for($school)->create();
    $mother = Guardian::factory()->for($school)->create();
    StudentGuardian::factory()->for($school)->for($student)->for($guardian)->primary()->create();
    StudentGuardian::factory()->for($school)->for($student)->for($mother)->create(['relationship' => GuardianRelationship::Mother]);
    $enrollment = Enrollment::factory()->for($school)->for($student)->forSection($sectionA)->create();

    $this->actingAs($actor);

    expect(fn () => (new AdmitStudent)->handle(
        $actor,
        validStudentPayload(),
        [['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => (new UpdateStudent)->handle($actor, $student, validStudentPayload()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new ChangeSection)->handle($actor, $enrollment, $sectionB))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new AttachGuardian)->handle($actor, $student, [
        'name' => 'Uncle',
        'mobile' => '9876543211',
        'relationship' => GuardianRelationship::Other->value,
        'is_primary' => false,
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => (new DetachGuardian)->handle($actor, $student, $mother))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new SetPrimaryGuardian)->handle($actor, $student, $mother))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new SuggestAdmissionNumber)->handle($actor))
        ->toThrow(AuthorizationException::class);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
    'Parent' => [SchoolRole::Parent],
    'Student' => [SchoolRole::Student],
]);

test('actions reject cross-school student, guardian, year, and section IDs', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $ownerA = createStudentActionActor($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->current()->create();
    $gradeB = Grade::factory()->for($schoolB)->create();
    $sectionB = Section::factory()->for($schoolB)->for($yearB)->for($gradeB)->create();
    $studentB = Student::factory()->for($schoolB)->create();
    $guardianB = Guardian::factory()->for($schoolB)->create();
    $enrollmentB = Enrollment::factory()->for($schoolB)->for($studentB)->forSection($sectionB)->create();

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolA->id);
    $this->actingAs($ownerA);

    $yearA = AcademicYear::factory()->for($schoolA)->current()->create();
    $gradeA = Grade::factory()->for($schoolA)->create();
    $sectionA = Section::factory()->for($schoolA)->for($yearA)->for($gradeA)->create();
    $studentA = Student::factory()->for($schoolA)->create();
    $guardianA = Guardian::factory()->for($schoolA)->create(['mobile' => '9876543210']);
    StudentGuardian::factory()->for($schoolA)->for($studentA)->for($guardianA)->primary()->create();
    $enrollmentA = Enrollment::factory()->for($schoolA)->for($studentA)->forSection($sectionA)->create();

    // Update, ChangeSection, and guardian actions reject foreign records.
    expect(fn () => (new UpdateStudent)->handle($ownerA, $studentB, validStudentPayload()))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => (new ChangeSection)->handle($ownerA, $enrollmentB, $sectionA))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => (new ChangeSection)->handle($ownerA, $enrollmentA, $sectionB))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => (new AttachGuardian)->handle($ownerA, $studentB, [
        'name' => 'Cross',
        'mobile' => '9876543211',
        'relationship' => 'father',
        'is_primary' => true,
    ]))->toThrow(ModelNotFoundException::class);

    expect(fn () => (new AttachGuardian)->handle($ownerA, $studentA, [
        'guardian_id' => $guardianB->id,
        'relationship' => 'mother',
        'is_primary' => false,
    ]))->toThrow(ValidationException::class);

    expect(fn () => (new DetachGuardian)->handle($ownerA, $studentA, $guardianB))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => (new SetPrimaryGuardian)->handle($ownerA, $studentA, $guardianB))
        ->toThrow(ModelNotFoundException::class);

    // AdmitStudent rejects School B section, academic year, and sibling guardian IDs.
    expect(fn () => (new AdmitStudent)->handle(
        $ownerA,
        validStudentPayload(['admission_no' => 'CROSS-01']),
        [['name' => 'Father', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $yearA->id, 'section_id' => $sectionB->id],
        true,
    ))->toThrow(ValidationException::class);

    expect(fn () => (new AdmitStudent)->handle(
        $ownerA,
        validStudentPayload(['admission_no' => 'CROSS-02']),
        [['guardian_id' => $guardianB->id, 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $yearA->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);
});

test('admitting and updating students never leak sensitive field values into activity_log', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create();

    $student = (new AdmitStudent)->handle(
        $owner,
        validStudentPayload([
            'admission_no' => 'SEC-001',
            'date_of_birth' => '2015-05-06',
            'social_category' => SocialCategory::St->value,
            'religion' => 'Jain',
            'apaar_id' => '998877665544',
            'pen' => '55443322110',
            'aadhaar_last4' => '8899',
        ]),
        [['name' => 'Nitin Shah', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year->id, 'section_id' => $section->id],
        true,
    );

    (new UpdateStudent)->handle($owner, $student, validStudentPayload([
        'admission_no' => 'SEC-001',
        'name' => 'Aarav N. Sharma',
        'date_of_birth' => '2015-05-07',
        'social_category' => SocialCategory::Obc->value,
        'religion' => 'Sikh',
        'apaar_id' => '998877665544',
        'pen' => '55443322110',
        'aadhaar_last4' => '6677',
    ]));

    $logs = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->id)
        ->orderBy('id')
        ->get();

    $serialized = json_encode($logs->toArray()) ?: '';

    expect($logs)->toHaveCount(2)
        ->and($serialized)
        ->not->toContain('2015-05-06')
        ->not->toContain('2015-05-07')
        ->not->toContain('Jain')
        ->not->toContain('Sikh')
        ->not->toContain('998877665544')
        ->not->toContain('55443322110')
        ->not->toContain('8899')
        ->not->toContain('6677')
        ->not->toContain('"st"')
        ->not->toContain('"obc"')
        ->and($logs[0]->causer_id)->toBe($owner->id)
        ->and($logs[1]->getProperty('sensitive_fields_changed'))
        ->toContain('social_category', 'religion', 'aadhaar_last4', 'date_of_birth');
});

test('admit student is all or nothing when the enrollment step fails', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create();

    Enrollment::creating(static function (): never {
        throw new RuntimeException('Simulated failure during enrollment creation.');
    });

    try {
        expect(fn () => (new AdmitStudent)->handle(
            $owner,
            validStudentPayload(['admission_no' => 'ROLLBACK-01']),
            [['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
            ['academic_year_id' => $year->id, 'section_id' => $section->id, 'roll_number' => 1],
            true,
        ))->toThrow(RuntimeException::class, 'Simulated failure during enrollment creation.');
    } finally {
        Enrollment::flushEventListeners();
        Enrollment::clearBootedModels();
    }

    expect(Student::query()->count())->toBe(0)
        ->and(Guardian::query()->count())->toBe(0)
        ->and(StudentGuardian::query()->count())->toBe(0)
        ->and(Enrollment::query()->count())->toBe(0);
});

test('admit student enforces consent, primary contact, guardian mobile, normalization, and profile validation rules', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $gradeA = Grade::factory()->for($school)->create(['name' => 'Grade 1']);
    $gradeB = Grade::factory()->for($school)->create(['name' => 'Grade 2']);
    $sectionA = Section::factory()->for($school)->for($year)->for($gradeA)->create();
    $sectionB = Section::factory()->for($school)->for($year)->for($gradeB)->create();

    $archivableGrade = Grade::factory()->for($school)->create(['name' => 'Old Grade']);
    $archivedSection = Section::factory()->for($school)->for($year)->for($archivableGrade)->create();
    $archivableGrade->forceFill(['archived_at' => Carbon::now()])->save();

    $validGuardian = [['name' => 'Ramesh Patel', 'mobile' => '98765 43210', 'relationship' => 'father', 'is_primary' => true]];

    // Consent required
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        $validGuardian,
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        false,
    ))->toThrow(ValidationException::class);

    // At least one guardian with mobile required
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        [['name' => 'Ramesh Patel', 'mobile' => null, 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    // Zero or two primary contacts rejected
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        [['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => false]],
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        [
            ['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true],
            ['name' => 'Meena Patel', 'mobile' => '9876543211', 'relationship' => 'mother', 'is_primary' => true],
        ],
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    // Max 3 guardians enforced
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        [
            ['name' => 'G1', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true],
            ['name' => 'G2', 'mobile' => '9876543211', 'relationship' => 'mother', 'is_primary' => false],
            ['name' => 'G3', 'mobile' => '9876543212', 'relationship' => 'grandparent', 'is_primary' => false],
            ['name' => 'G4', 'mobile' => '9876543213', 'relationship' => 'other', 'is_primary' => false],
        ],
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    // Invalid IndianState spelling rejected
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['state' => 'Gujrat']),
        $validGuardian,
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    // Age under 2 years or over 20 years rejected
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['date_of_birth' => now()->subYear()->toDateString()]),
        $validGuardian,
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['date_of_birth' => now()->subYears(22)->toDateString()]),
        $validGuardian,
        ['academic_year_id' => $year->id, 'section_id' => $sectionA->id],
        true,
    ))->toThrow(ValidationException::class);

    // Scoped grade_id + archived grade rejected
    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        $validGuardian,
        ['academic_year_id' => $year->id, 'grade_id' => $gradeA->id, 'section_id' => $sectionB->id],
        true,
    ))->toThrow(ValidationException::class);

    expect(fn () => (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(),
        $validGuardian,
        ['academic_year_id' => $year->id, 'section_id' => $archivedSection->id],
        true,
    ))->toThrow(ValidationException::class);

    // Successful admission normalizes guardian mobile and records DPDP consent timestamp & actor.
    $admitted = (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['admission_no' => 'SPS-0142']),
        $validGuardian,
        ['academic_year_id' => $year->id, 'grade_id' => $gradeA->id, 'section_id' => $sectionA->id, 'roll_number' => 7],
        true,
    );

    expect($admitted->status)->toBe(StudentStatus::Active)
        ->and($admitted->consent_recorded_by)->toBe($owner->id)
        ->and($admitted->consent_recorded_at)->not->toBeNull()
        ->and($admitted->guardians->first()?->mobile)->toBe('+919876543210')
        ->and($admitted->enrollments->first()?->roll_number)->toBe(7);
});

test('sibling admission shares the existing guardian row and guardian actions protect primary and last contact invariants', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create();

    $firstChild = (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['admission_no' => 'SIB-01', 'apaar_id' => null]),
        [['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year->id, 'section_id' => $section->id, 'roll_number' => 1],
        true,
    );

    $father = $firstChild->guardians->firstOrFail();

    // Admit sibling linking directly to the existing guardian record.
    $secondChild = (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['admission_no' => 'SIB-02', 'name' => 'Diya Patel', 'gender' => Gender::Female->value, 'apaar_id' => null]),
        [['guardian_id' => $father->id, 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year->id, 'section_id' => $section->id, 'roll_number' => 2],
        true,
    );

    expect(Guardian::query()->count())->toBe(1)
        ->and($secondChild->guardians->pluck('id')->all())->toBe([$father->id]);

    // Cannot detach the student's last guardian.
    expect(fn () => (new DetachGuardian)->handle($owner, $firstChild, $father))
        ->toThrow(ValidationException::class);

    // Attach mother without mobile (allowed because father has a mobile).
    $motherLink = (new AttachGuardian)->handle($owner, $firstChild, [
        'name' => 'Meena Patel',
        'mobile' => null,
        'relationship' => GuardianRelationship::Mother->value,
        'is_primary' => false,
    ]);
    $mother = $motherLink->guardian;

    // Duplicate guardian link on same student refused.
    expect(fn () => (new AttachGuardian)->handle($owner, $firstChild, [
        'guardian_id' => $father->id,
        'relationship' => 'father',
        'is_primary' => false,
    ]))->toThrow(ValidationException::class);

    // Cannot remove primary contact without choosing replacement.
    expect(fn () => (new DetachGuardian)->handle($owner, $firstChild, $father))
        ->toThrow(ValidationException::class);

    // Cannot remove father (even specifying mother as replacement) while mother has no mobile.
    expect(fn () => (new DetachGuardian)->handle($owner, $firstChild, $father, $mother))
        ->toThrow(ValidationException::class);

    // Add aunt with mobile as new primary, switch primary, then detach father cleanly while naming aunt as primary.
    $auntLink = (new AttachGuardian)->handle($owner, $firstChild, [
        'name' => 'Kavita Patel',
        'mobile' => '9876543299',
        'relationship' => GuardianRelationship::Other->value,
        'is_primary' => false,
    ]);
    $aunt = $auntLink->guardian;

    // 4th guardian refused.
    expect(fn () => (new AttachGuardian)->handle($owner, $firstChild, [
        'name' => 'Fourth Guardian',
        'mobile' => '9876543288',
        'relationship' => GuardianRelationship::Guardian->value,
        'is_primary' => false,
    ]))->toThrow(ValidationException::class);

    (new SetPrimaryGuardian)->handle($owner, $firstChild, $mother);

    expect($motherLink->fresh()?->isPrimary())->toBeTrue()
        ->and($firstChild->studentGuardians()->where('is_primary', true)->count())->toBe(1);

    // Detach current primary (mother) while assigning aunt as replacement primary.
    (new DetachGuardian)->handle($owner, $firstChild, $mother, $aunt);

    expect($firstChild->studentGuardians()->count())->toBe(2)
        ->and($auntLink->fresh()?->isPrimary())->toBeTrue();
});

test('change section updates enrollment in place within the same year and blocks cross-year or archived transfers', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year1 = AcademicYear::factory()->for($school)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $year2 = AcademicYear::factory()->for($school)->create([
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
        'is_current' => false,
    ]);
    $grade = Grade::factory()->for($school)->create();
    $archivableGrade = Grade::factory()->for($school)->create();
    $sectionA = Section::factory()->for($school)->for($year1)->for($grade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($year1)->for($grade)->create(['name' => 'B']);
    $sectionOtherYear = Section::factory()->for($school)->for($year2)->for($grade)->create(['name' => 'A']);
    $archivedSection = Section::factory()->for($school)->for($year1)->for($archivableGrade)->create(['name' => 'A']);
    $archivableGrade->forceFill(['archived_at' => Carbon::now()])->save();

    $student = (new AdmitStudent)->handle(
        $owner,
        validStudentPayload(['admission_no' => 'SEC-MOVE-01']),
        [['name' => 'Ramesh Patel', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
        ['academic_year_id' => $year1->id, 'section_id' => $sectionA->id, 'roll_number' => 4],
        true,
    );

    $enrollment = $student->enrollments->firstOrFail();
    $originalId = $enrollment->id;

    // Cross-year section change is rejected.
    expect(fn () => (new ChangeSection)->handle($owner, $enrollment, $sectionOtherYear))
        ->toThrow(ValidationException::class);

    // Archived grade section is rejected.
    expect(fn () => (new ChangeSection)->handle($owner, $enrollment, $archivedSection))
        ->toThrow(ValidationException::class);

    // Same-year transfer keeps the exact enrollment ID.
    $updated = (new ChangeSection)->handle($owner, $enrollment, $sectionB, 11);

    expect($updated->id)->toBe($originalId)
        ->and($updated->section_id)->toBe($sectionB->id)
        ->and($updated->roll_number)->toBe(11)
        ->and(Enrollment::query()->count())->toBe(1);

    // Closed year blocks section changes.
    $year1->forceFill(['is_current' => false, 'closed_at' => Carbon::now()])->save();
    expect(fn () => (new ChangeSection)->handle($owner, $updated, $sectionA))
        ->toThrow(ValidationException::class);
});

test('suggest admission number preserves prefix and zero padding, handles collisions, and update keeps admission no editable', function () {
    $school = School::factory()->create();
    $owner = createStudentActionActor($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $this->actingAs($owner);

    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create();

    $suggester = new SuggestAdmissionNumber;

    // Empty school falls back to default ADM-YYYY-0001 format.
    expect($suggester->handle($owner))->toBe('ADM-'.now()->format('Y').'-0001');

    // Non-numeric ending returns null.
    Student::factory()->for($school)->create(['admission_no' => '2026-A']);
    expect($suggester->handle($owner))->toBeNull();

    // Numeric suffix preserves prefix and zero padding.
    Student::factory()->for($school)->create(['admission_no' => 'SPS-0142']);
    expect($suggester->handle($owner))->toBe('SPS-0143');

    // Skips already-taken sequential candidates cleanly.
    Student::factory()->for($school)->create(['admission_no' => 'SPS-0143']);
    $latest = Student::factory()->for($school)->create(['admission_no' => 'SPS-0141']);
    expect($suggester->handle($owner))->toBe('SPS-0144');

    // Rollover padding grows naturally (99 -> 100).
    Student::factory()->for($school)->create(['admission_no' => '2026/0099']);
    expect($suggester->handle($owner))->toBe('2026/0100');

    // UpdateStudent allows editing admission_no for typos and returns friendly collision suggestion.
    $updatedLatest = (new UpdateStudent)->handle($owner, $latest, validStudentPayload([
        'admission_no' => 'SPS-0199',
        'apaar_id' => null,
    ]));

    expect($updatedLatest->admission_no)->toBe('SPS-0199');

    try {
        (new AdmitStudent)->handle(
            $owner,
            validStudentPayload(['admission_no' => 'SPS-0199', 'apaar_id' => null]),
            [['name' => 'Parent', 'mobile' => '9876543210', 'relationship' => 'father', 'is_primary' => true]],
            ['academic_year_id' => $year->id, 'section_id' => $section->id],
            true,
        );
        $this->fail('Expected ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['admission_no'][0] ?? '')
            ->toContain('taken')
            ->toContain('SPS-0200');
    }

    // Simulated database unique race during admission rolls back cleanly and suggests next free number.
    Student::creating(static function () use ($school): void {
        static $raced = false;
        if (! $raced) {
            $raced = true;
            DB::table('students')->insert([
                'id' => (string) Str::ulid(),
                'school_id' => $school->id,
                'admission_no' => 'SPS-0500',
                'admission_date' => '2026-04-01',
                'name' => 'Concurrent Student',
                'date_of_birth' => '2016-01-01',
                'gender' => 'male',
                'nationality' => 'Indian',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $guardiansBeforeRace = Guardian::query()->count();
    $enrollmentsBeforeRace = Enrollment::query()->count();

    try {
        (new AdmitStudent)->handle(
            $owner,
            validStudentPayload(['admission_no' => 'SPS-0500', 'apaar_id' => null]),
            [['name' => 'Race Parent', 'mobile' => '9876543222', 'relationship' => 'father', 'is_primary' => true]],
            ['academic_year_id' => $year->id, 'section_id' => $section->id],
            true,
        );
        $this->fail('Expected ValidationException on DB race was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['admission_no'][0] ?? '')
            ->toContain('taken')
            ->toContain('try');
    } finally {
        Student::flushEventListeners();
        Student::clearBootedModels();
    }

    expect(Guardian::query()->count())->toBe($guardiansBeforeRace)
        ->and(Enrollment::query()->count())->toBe($enrollmentsBeforeRace);
});
