<?php

use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
use App\Livewire\Students\Create as StudentsCreate;
use App\Livewire\Students\Edit as StudentsEdit;
use App\Livewire\Students\Index as StudentsIndex;
use App\Livewire\Students\Show as StudentsShow;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
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
 * Provision an active member in the school with the given role.
 */
function createStudentScreenMember(School $school, SchoolRole $role): User
{
    $user = User::factory()->create();

    Membership::factory()->active()->for($user)->for($school)->create([
        'status' => MembershipStatus::Active,
    ]);

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return $user;
}

/**
 * Create a seeded academic year, grade, section, student, guardian, and enrollment for screen tests.
 *
 * @return array{year: AcademicYear, grade: Grade, section: Section, student: Student, guardian: Guardian, enrollment: Enrollment}
 */
function createSeededStudentInSchool(School $school, array $studentOverrides = []): array
{
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 3']);
    $section = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);

    $student = Student::factory()->for($school)->create(array_merge([
        'admission_no' => 'SPS-0142',
        'admission_date' => '2026-04-01',
        'name' => 'Aarav Patel',
        'date_of_birth' => '2015-06-05',
        'gender' => Gender::Male,
        'social_category' => SocialCategory::Obc,
        'religion' => 'CanaryReligion',
        'apaar_id' => '918273645123',
        'pen' => '55667788991',
        'aadhaar_last4' => '7391',
    ], $studentOverrides));

    $guardian = Guardian::factory()->for($school)->create([
        'name' => 'Ramesh Patel',
        'mobile' => '9876543210',
    ]);

    StudentGuardian::factory()->for($school)->for($student)->for($guardian)->primary()->create([
        'relationship' => GuardianRelationship::Father,
    ]);

    $enrollment = Enrollment::factory()->for($school)->for($student)->forSection($section)->create([
        'roll_number' => 1,
        'enrolled_on' => '2026-04-01',
    ]);

    CurrentSchool::forget();

    return compact('year', 'grade', 'section', 'student', 'guardian', 'enrollment');
}

test('student routes enforce role-based HTTP permissions and non-member isolation', function (
    SchoolRole $role,
    int $listAndShowStatus,
    int $createAndEditStatus,
) {
    $school = School::factory()->active()->create();
    $user = createStudentScreenMember($school, $role);
    $seeded = createSeededStudentInSchool($school);
    $student = $seeded['student'];

    $this->actingAs($user)->get(route('students.index', ['school' => $school->id]))
        ->assertStatus($listAndShowStatus);

    $this->actingAs($user)->get(route('students.show', ['school' => $school->id, 'student' => $student->id]))
        ->assertStatus($listAndShowStatus);

    $this->actingAs($user)->get(route('students.create', ['school' => $school->id]))
        ->assertStatus($createAndEditStatus);

    $this->actingAs($user)->get(route('students.edit', ['school' => $school->id, 'student' => $student->id]))
        ->assertStatus($createAndEditStatus);
})->with([
    'Owner' => [SchoolRole::Owner, 200, 200],
    'Admin' => [SchoolRole::Admin, 200, 200],
    'Teacher' => [SchoolRole::Teacher, 200, 403],
    'Accountant' => [SchoolRole::Accountant, 200, 403],
    'Parent' => [SchoolRole::Parent, 403, 403],
    'Student' => [SchoolRole::Student, 403, 403],
]);

test('guests redirect to login and non-members receive 404 on student routes', function () {
    $school = School::factory()->active()->create();
    $seeded = createSeededStudentInSchool($school);
    $outsider = User::factory()->create();

    $routes = [
        route('students.index', ['school' => $school->id]),
        route('students.create', ['school' => $school->id]),
        route('students.show', ['school' => $school->id, 'student' => $seeded['student']->id]),
        route('students.edit', ['school' => $school->id, 'student' => $seeded['student']->id]),
    ];

    foreach ($routes as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    foreach ($routes as $url) {
        $this->actingAs($outsider)->get($url)->assertNotFound();
    }
});

test('teacher and accountant never receive sensitive fields in page HTML or Livewire snapshots while owner and admin see them', function () {
    $school = School::factory()->active()->create();
    $seeded = createSeededStudentInSchool($school, [
        'social_category' => SocialCategory::St,
        'religion' => 'Zoroastrian-Canary',
        'apaar_id' => '918273645123',
        'pen' => '55667788991',
        'aadhaar_last4' => '7391',
    ]);
    $student = $seeded['student'];

    $owner = createStudentScreenMember($school, SchoolRole::Owner);
    $admin = createStudentScreenMember($school, SchoolRole::Admin);
    $teacher = createStudentScreenMember($school, SchoolRole::Teacher);
    $accountant = createStudentScreenMember($school, SchoolRole::Accountant);

    foreach ([$teacher, $accountant] as $restrictedViewer) {
        $listHtml = $this->actingAs($restrictedViewer)
            ->get(route('students.index', ['school' => $school->id]))
            ->assertOk()
            ->getContent();

        $profileResponse = $this->actingAs($restrictedViewer)
            ->get(route('students.show', ['school' => $school->id, 'student' => $student->id]))
            ->assertOk();

        $profileHtml = (string) $profileResponse->getContent();

        foreach (['Zoroastrian-Canary', '918273645123', '55667788991', '7391'] as $canary) {
            expect((string) $listHtml)->not->toContain($canary);
            expect($profileHtml)->not->toContain($canary);
        }

        expect($profileHtml)
            ->not->toContain('social_category')
            ->not->toContain('aadhaar_last4')
            ->not->toContain('apaar_id')
            ->not->toContain('data-test="sensitive-student-details"')
            ->not->toContain('data-test="edit-student-profile-button"');

        // Verify the Livewire component snapshot itself contains zero sensitive attributes.
        CurrentSchool::set($school);
        setPermissionsTeamId($school->id);
        $lw = Livewire::actingAs($restrictedViewer)->test(StudentsShow::class, ['student' => $student]);
        $snapshotJson = json_encode($lw->snapshot) ?: '';
        CurrentSchool::forget();
        setPermissionsTeamId(null);

        foreach (['Zoroastrian-Canary', '918273645123', '55667788991', '7391', 'social_category', 'aadhaar_last4', 'apaar_id'] as $canary) {
            expect($snapshotJson)->not->toContain($canary);
        }
    }

    foreach ([$owner, $admin] as $privilegedViewer) {
        $this->actingAs($privilegedViewer)
            ->get(route('students.show', ['school' => $school->id, 'student' => $student->id]))
            ->assertOk()
            ->assertSee('data-test="sensitive-student-details"', false)
            ->assertSee('Zoroastrian-Canary')
            ->assertSee('918273645123')
            ->assertSee('55667788991')
            ->assertSee('7391');
    }
});

test('teacher cannot admit or edit via Livewire actions even when calling components directly', function () {
    $school = School::factory()->active()->create();
    $teacher = createStudentScreenMember($school, SchoolRole::Teacher);
    $seeded = createSeededStudentInSchool($school);

    $this->actingAs($teacher);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    Livewire::test(StudentsCreate::class)->assertForbidden();
    Livewire::test(StudentsEdit::class, ['student' => $seeded['student']])->assertForbidden();
});

test('cross-school student, guardian, and section IDs are rejected across routes and Livewire tampering', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $ownerA = createStudentScreenMember($schoolA, SchoolRole::Owner);
    $seededA = createSeededStudentInSchool($schoolA);
    $seededB = createSeededStudentInSchool($schoolB, [
        'admission_no' => 'FOREIGN-001',
        'apaar_id' => null,
    ]);

    // Route model binding returns 404 when opening School B's student inside School A.
    $this->actingAs($ownerA)
        ->get(route('students.show', ['school' => $schoolA->id, 'student' => $seededB['student']->id]))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->get(route('students.edit', ['school' => $schoolA->id, 'student' => $seededB['student']->id]))
        ->assertNotFound();

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolA->id);

    // Locked studentId on Show cannot be forged.
    expect(fn () => Livewire::actingAs($ownerA)
        ->test(StudentsShow::class, ['student' => $seededA['student']])
        ->set('studentId', $seededB['student']->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // Sibling linking rejects School B's guardian ID via school scope (404) and locks linkedGuardianIds.
    Livewire::actingAs($ownerA)
        ->test(StudentsCreate::class)
        ->call('linkSiblingGuardian', 0, $seededB['guardian']->id)
        ->assertNotFound();

    expect(fn () => Livewire::actingAs($ownerA)
        ->test(StudentsCreate::class)
        ->set('linkedGuardianIds', [$seededB['guardian']->id]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // Forged section_id from School B fails scoped placement validation on Create.
    Livewire::actingAs($ownerA)
        ->test(StudentsCreate::class)
        ->set('admission_no', 'SPS-9991')
        ->set('admission_date', '01/04/2026')
        ->set('name', 'Test Child')
        ->set('date_of_birth', '05/06/2015')
        ->set('academic_year_id', $seededA['year']->id)
        ->set('grade_id', $seededA['grade']->id)
        ->set('section_id', $seededB['section']->id)
        ->set('guardians.0.name', 'Parent A')
        ->set('guardians.0.mobile', '9876543211')
        ->set('consent_confirmed', true)
        ->call('save')
        ->assertHasErrors(['section_id']);

    // Edit sibling lookup / attach / primary / detach reject School B's guardian ID with 404.
    Livewire::actingAs($ownerA)
        ->test(StudentsEdit::class, ['student' => $seededA['student']])
        ->call('linkExistingGuardian', $seededB['guardian']->id)
        ->assertNotFound();

    Livewire::actingAs($ownerA)
        ->test(StudentsEdit::class, ['student' => $seededA['student']])
        ->call('setPrimaryGuardian', $seededB['guardian']->id)
        ->assertNotFound();

    Livewire::actingAs($ownerA)
        ->test(StudentsEdit::class, ['student' => $seededA['student']])
        ->call('detachGuardian', $seededB['guardian']->id)
        ->assertNotFound();
});

test('admission screen prefills suggestion, parses dd/mm/yyyy strictly, links sibling by locked ID, and displays siblings on profile', function () {
    $school = School::factory()->active()->create();
    $owner = createStudentScreenMember($school, SchoolRole::Owner);
    $seeded = createSeededStudentInSchool($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    // Invalid calendar date or mm/dd overflow rejected cleanly.
    Livewire::actingAs($owner)
        ->test(StudentsCreate::class)
        ->assertSet('admission_no', 'SPS-0143')
        ->set('admission_date', '01/04/2026')
        ->set('name', 'Bad Date Child')
        ->set('date_of_birth', '31/02/2015')
        ->call('save')
        ->assertHasErrors(['date_of_birth']);

    // Admitting second child parses 05/06/2015 as 5 June 2015 and links Ramesh Patel via locked sibling ID.
    $createComponent = Livewire::actingAs($owner)
        ->test(StudentsCreate::class)
        ->set('admission_date', '01/04/2026')
        ->set('name', 'Diya Patel')
        ->set('date_of_birth', '05/06/2015')
        ->set('gender', Gender::Female->value)
        ->set('academic_year_id', $seeded['year']->id)
        ->set('grade_id', $seeded['grade']->id)
        ->set('section_id', $seeded['section']->id)
        ->set('roll_number', '2')
        ->set('guardians.0.mobile', '98765 43210')
        ->assertSee('Ramesh Patel (Father of Aarav Patel (Class 3 - A))')
        ->call('linkSiblingGuardian', 0, $seeded['guardian']->id)
        ->set('consent_confirmed', true)
        ->call('save')
        ->assertHasNoErrors();

    $diya = Student::query()->where('admission_no', 'SPS-0143')->firstOrFail();

    expect($diya->date_of_birth->toDateString())->toBe('2015-06-05')
        ->and($diya->guardians->pluck('id')->all())->toBe([$seeded['guardian']->id])
        ->and(Guardian::query()->count())->toBe(1);

    $createComponent->assertRedirect(route('students.show', ['school' => $school->id, 'student' => $diya->id]));

    // Sibling appears cleanly on the Guardians tab of the profile.
    Livewire::actingAs($owner)
        ->test(StudentsShow::class, ['student' => $diya])
        ->call('setTab', 'guardians')
        ->assertSee('Ramesh Patel')
        ->assertSee('Aarav Patel')
        ->assertSee('SPS-0142')
        ->call('setTab', 'history')
        ->assertSee('2026-27')
        ->assertSee('Class 3 - A');
});

test('students list filters by year, grade, section, status, normalized guardian mobile, and escapes LIKE wildcards', function () {
    $school = School::factory()->active()->create();
    $owner = createStudentScreenMember($school, SchoolRole::Owner);
    $seeded = createSeededStudentInSchool($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $grade5 = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);
    $section5B = Section::factory()->for($school)->for($seeded['year'])->for($grade5)->create(['name' => 'B']);
    $student2 = Student::factory()->for($school)->create([
        'admission_no' => 'SPS-0200',
        'name' => '100%_Match Candidate',
        'status' => StudentStatus::Left,
    ]);
    $guardian2 = Guardian::factory()->for($school)->create([
        'name' => 'Meera Verma',
        'mobile' => '9123456789',
    ]);
    StudentGuardian::factory()->for($school)->for($student2)->for($guardian2)->primary()->create();
    Enrollment::factory()->for($school)->for($student2)->forSection($section5B)->create([
        'status' => EnrollmentStatus::Left,
        'enrolled_on' => '2026-04-01',
    ]);

    // Filter by grade -> section
    Livewire::actingAs($owner)
        ->test(StudentsIndex::class)
        ->assertSee('Aarav Patel')
        ->assertSee('100%_Match Candidate')
        ->set('grade_id', $grade5->id)
        ->assertDontSee('Aarav Patel')
        ->assertSee('100%_Match Candidate')
        ->set('section_id', $section5B->id)
        ->assertSee('100%_Match Candidate');

    // Filter by status
    Livewire::actingAs($owner)
        ->test(StudentsIndex::class)
        ->set('status', StudentStatus::Active->value)
        ->assertSee('Aarav Patel')
        ->assertDontSee('100%_Match Candidate');

    // Normalized guardian mobile search ('98765 43210' and '09876543210') finds Aarav Patel.
    Livewire::actingAs($owner)
        ->test(StudentsIndex::class)
        ->set('search', '98765 43210')
        ->assertSee('Aarav Patel')
        ->assertDontSee('100%_Match Candidate')
        ->set('search', '09876543210')
        ->assertSee('Aarav Patel')
        ->assertDontSee('100%_Match Candidate');

    // Escaped LIKE search for '%' matches only the literal '%' student, not Aarav Patel.
    Livewire::actingAs($owner)
        ->test(StudentsIndex::class)
        ->set('search', '100%')
        ->assertSee('100%_Match Candidate')
        ->assertDontSee('Aarav Patel');
});

test('students index executes a constant number of queries for 10 and 200 students', function () {
    $smallSchool = School::factory()->active()->create();
    $largeSchool = School::factory()->active()->create();

    $smallOwner = createStudentScreenMember($smallSchool, SchoolRole::Owner);
    $largeOwner = createStudentScreenMember($largeSchool, SchoolRole::Owner);

    foreach ([[$smallSchool, 10], [$largeSchool, 200]] as [$targetSchool, $studentCount]) {
        CurrentSchool::set($targetSchool);
        $year = AcademicYear::factory()->for($targetSchool)->current()->create([
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
        ]);
        $grades = Grade::factory()->for($targetSchool)->count(4)->create();
        $sections = collect();
        foreach ($grades as $grade) {
            $sections->push(Section::factory()->for($targetSchool)->for($year)->for($grade)->create(['name' => 'A']));
        }

        for ($i = 0; $i < $studentCount; $i++) {
            $section = $sections[$i % $sections->count()];
            $student = Student::factory()->for($targetSchool)->create(['admission_no' => "ADM-{$i}"]);
            $guardian = Guardian::factory()->for($targetSchool)->create();
            StudentGuardian::factory()->for($targetSchool)->for($student)->for($guardian)->primary()->create();
            Enrollment::factory()->for($targetSchool)->for($student)->forSection($section)->create([
                'enrolled_on' => '2026-04-01',
            ]);
        }

        CurrentSchool::forget();
    }

    // Warm permission caches before measuring steady-state queries.
    $this->actingAs($smallOwner)->get(route('students.index', ['school' => $smallSchool->id]))->assertOk();
    $this->actingAs($largeOwner)->get(route('students.index', ['school' => $largeSchool->id]))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($smallOwner)->get(route('students.index', ['school' => $smallSchool->id]))->assertOk();
    $smallQueryCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->actingAs($largeOwner)->get(route('students.index', ['school' => $largeSchool->id]))->assertOk();
    $largeQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeQueryCount)
        ->toBe($smallQueryCount)
        ->toBeLessThanOrEqual(20);
});

test('edit screen updates profile, changes section in place, manages guardians, and escapes XSS safely', function () {
    $school = School::factory()->active()->create();
    $owner = createStudentScreenMember($school, SchoolRole::Owner);
    $seeded = createSeededStudentInSchool($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $sectionB = Section::factory()->for($school)->for($seeded['year'])->for($seeded['grade'])->create(['name' => 'B']);
    $enrollmentId = $seeded['enrollment']->id;
    $xssName = "O'Connor <script>alert('xss')</script>";

    $editComponent = Livewire::actingAs($owner)
        ->test(StudentsEdit::class, ['student' => $seeded['student']])
        ->set('name', $xssName)
        ->set('state', IndianState::Maharashtra->value)
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->set('section_id', $sectionB->id)
        ->set('roll_number', '15')
        ->call('saveSection')
        ->assertHasNoErrors()
        ->set('new_guardian_name', 'Meena Patel')
        ->set('new_guardian_mobile', '9876543211')
        ->set('new_guardian_relationship', GuardianRelationship::Mother->value)
        ->call('attachGuardian')
        ->assertHasNoErrors();

    $student = $seeded['student']->fresh();
    expect($student?->name)->toBe($xssName)
        ->and($student?->state)->toBe(IndianState::Maharashtra)
        ->and($seeded['enrollment']->fresh()?->id)->toBe($enrollmentId)
        ->and($seeded['enrollment']->fresh()?->section_id)->toBe($sectionB->id)
        ->and($seeded['enrollment']->fresh()?->roll_number)->toBe(15)
        ->and($student?->studentGuardians()->count())->toBe(2);

    $mother = $student?->guardians()->where('name', 'Meena Patel')->firstOrFail();

    // Open replace-and-detach primary flow on Edit screen.
    $editComponent->call('openDetachPrimaryModal', $seeded['guardian']->id)
        ->set('replacementPrimaryGuardianId', $mother->id)
        ->call('confirmDetachPrimary')
        ->assertHasNoErrors();

    expect($student?->studentGuardians()->count())->toBe(1)
        ->and($student?->studentGuardians()->first()?->isPrimary())->toBeTrue()
        ->and($student?->studentGuardians()->first()?->guardian_id)->toBe($mother->id);

    // XSS payload renders escaped on Profile and List pages.
    $this->actingAs($owner)
        ->get(route('students.show', ['school' => $school->id, 'student' => $seeded['student']->id]))
        ->assertOk()
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee("<script>alert('xss')</script>", false);

    $this->actingAs($owner)
        ->get(route('students.index', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee("<script>alert('xss')</script>", false);
});

test('admitting into a future open academic year uses the academic year start date for enrolled_on and shows validation summaries visibly', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 10:00:00', 'Asia/Kolkata'));

    try {
        $school = School::factory()->active()->create();
        $owner = createStudentScreenMember($school, SchoolRole::Owner);
        $seeded = createSeededStudentInSchool($school);

        CurrentSchool::set($school);
        setPermissionsTeamId($school->id);

        $futureYear = AcademicYear::factory()->for($school)->create([
            'name' => '2027-28',
            'starts_on' => '2027-04-01',
            'ends_on' => '2028-03-31',
            'is_current' => false,
            'closed_at' => null,
        ]);
        $futureSection = Section::factory()->for($school)->for($futureYear)->for($seeded['grade'])->create(['name' => 'A']);

        // Validation error renders visibly in the form error summary callout.
        Livewire::actingAs($owner)
            ->test(StudentsCreate::class)
            ->assertSet('admission_date', '26/11/2026')
            ->set('name', 'Future Student')
            ->set('date_of_birth', '05/06/2018')
            ->set('academic_year_id', $futureYear->id)
            ->set('grade_id', $seeded['grade']->id)
            ->set('section_id', $futureSection->id)
            ->set('guardians.0.name', 'Suresh Patel')
            ->set('guardians.0.mobile', '9876543219')
            ->set('consent_confirmed', false)
            ->call('save')
            ->assertHasErrors(['consent_confirmed'])
            ->assertSee('data-test="form-error-summary"', false);

        // Confirming consent succeeds cleanly and sets enrolled_on to 2027-04-01 (starts_on of 2027-28).
        Livewire::actingAs($owner)
            ->test(StudentsCreate::class)
            ->assertSet('admission_date', '26/11/2026')
            ->set('name', 'Future Student')
            ->set('date_of_birth', '05/06/2018')
            ->set('academic_year_id', $futureYear->id)
            ->set('grade_id', $seeded['grade']->id)
            ->set('section_id', $futureSection->id)
            ->set('guardians.0.name', 'Suresh Patel')
            ->set('guardians.0.mobile', '9876543219')
            ->set('consent_confirmed', true)
            ->call('save')
            ->assertHasNoErrors();

        $admittedStudent = Student::query()->where('name', 'Future Student')->firstOrFail();
        $enrollment = $admittedStudent->enrollments()->firstOrFail();

        expect($admittedStudent->admission_date->toDateString())->toBe('2026-11-26')
            ->and($enrollment->academic_year_id)->toBe($futureYear->id)
            ->and($enrollment->enrolled_on->toDateString())->toBe('2027-04-01');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
