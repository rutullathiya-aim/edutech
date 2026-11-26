<?php

use App\Enums\MembershipStatus;
use App\Enums\SchoolBoard;
use App\Enums\SchoolPermission;
use App\Enums\SchoolRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Create a user with an active membership and the given school role inside the school.
 */
function setupUserForDashboard(School $school, ?SchoolRole $role = null, ?User $existingUser = null): User
{
    test()->seed(PermissionSeeder::class);

    $user = $existingUser ?? User::factory()->create();

    Membership::factory()->active()->for($user)->for($school)->create([
        'status' => MembershipStatus::Active,
    ]);

    if ($role !== null) {
        setPermissionsTeamId($school->id);
        $user->unsetRelation('roles', 'permissions');
        $user->assignRole($role->value);
        $user->unsetRelation('roles', 'permissions');
        setPermissionsTeamId(null);
    }

    return $user;
}

it('redirects guests to the login page', function () {
    $school = School::factory()->create();

    $this->get(route('dashboard', ['school' => $school->id]))
        ->assertRedirect(route('login'));
});

it('allows authenticated active members to visit the dashboard', function () {
    $school = School::factory()->active()->create([
        'name' => 'Sunrise Public School',
    ]);
    $user = setupUserForDashboard($school);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Sunrise Public School')
        ->assertSee('Welcome to Sunrise Public School');
});

it('displays school header metadata and current academic year or warning', function () {
    $school = School::factory()->active()->create([
        'name' => 'Navodaya Vidyalaya',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'board' => SchoolBoard::Cbse,
    ]);
    $owner = setupUserForDashboard($school, SchoolRole::Owner);

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Navodaya Vidyalaya')
        ->assertSee('Pune, Maharashtra')
        ->assertSee('CBSE')
        ->assertSee('No current academic year')
        ->assertDontSee('data-test="current-year-badge"', false);

    CurrentSchool::set($school);
    AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        'is_current' => true,
    ]);
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Current year: 2026-27')
        ->assertDontSee('data-test="no-current-year-warning"', false);
});

it('shows the trial banner only to Owner and Admin on trial schools', function () {
    $trialSchool = School::factory()->create([
        'trial_ends_at' => now('Asia/Kolkata')->addDays(14)->endOfDay()->startOfSecond()->utc(),
    ]);

    $owner = setupUserForDashboard($trialSchool, SchoolRole::Owner);
    $admin = setupUserForDashboard($trialSchool, SchoolRole::Admin);
    $teacher = setupUserForDashboard($trialSchool, SchoolRole::Teacher);
    $accountant = setupUserForDashboard($trialSchool, SchoolRole::Accountant);
    $parent = setupUserForDashboard($trialSchool, SchoolRole::Parent);
    $student = setupUserForDashboard($trialSchool, SchoolRole::Student);

    foreach ([$owner, $admin] as $manager) {
        $this->actingAs($manager)
            ->get(route('dashboard', ['school' => $trialSchool->id]))
            ->assertOk()
            ->assertSee('data-test="trial-banner"', false)
            ->assertSee('Your trial ends in 14 days');
    }

    foreach ([$teacher, $accountant, $parent, $student] as $nonManager) {
        $this->actingAs($nonManager)
            ->get(route('dashboard', ['school' => $trialSchool->id]))
            ->assertOk()
            ->assertDontSee('data-test="trial-banner"', false)
            ->assertDontSee('Your trial ends in');
    }

    $activeSchool = School::factory()->active()->create([
        'trial_ends_at' => now()->addDays(14),
    ]);
    $activeOwner = setupUserForDashboard($activeSchool, SchoolRole::Owner);

    $this->actingAs($activeOwner)
        ->get(route('dashboard', ['school' => $activeSchool->id]))
        ->assertOk()
        ->assertDontSee('data-test="trial-banner"', false)
        ->assertDontSee('Your trial ends in');
});

it('tracks each setup checklist step accurately from 0 of 6 until completion hides it', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserForDashboard($school, SchoolRole::Owner);

    // 0 of 6 on an empty school (step 5 must stay incomplete when 0 active grades exist)
    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="setup-checklist"', false)
        ->assertSee('0 of 6 complete')
        ->assertDontSee('✅')
        ->assertSee(route('setup.academic-years', ['school' => $school->id]), false)
        ->assertSee(route('setup.grades', ['school' => $school->id]), false)
        ->assertSee(route('setup.sections', ['school' => $school->id]), false)
        ->assertSee(route('setup.subjects', ['school' => $school->id]), false)
        ->assertSee(route('staff.index', ['school' => $school->id]), false);

    CurrentSchool::set($school);

    // Step 1: non-current year should not satisfy step 1, current year should.
    $oldYear = AcademicYear::factory()->for($school)->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
        'is_current' => false,
    ]);

    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('0 of 6 complete');

    CurrentSchool::set($school);
    $currentYear = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        'is_current' => true,
    ]);
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('1 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-academic-year"', '✅', 'data-test="checklist-step-grades"', '⬜']);

    // Step 2: archived grade alone leaves step 2 incomplete; active grade completes step 2.
    CurrentSchool::set($school);
    Grade::factory()->archived()->for($school)->create(['name' => 'Archived Class', 'sort_order' => 99]);
    $gradeOne = Grade::factory()->for($school)->create(['name' => 'Class 1', 'sort_order' => 1]);
    $gradeTwo = Grade::factory()->for($school)->create(['name' => 'Class 2', 'sort_order' => 2]);
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('2 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-grades"', '✅', 'data-test="checklist-step-sections"', '⬜']);

    // Step 3: section in an older year does not complete step 3; section in current year does.
    CurrentSchool::set($school);
    Section::factory()->for($school)->for($oldYear, 'academicYear')->for($gradeOne)->create(['name' => 'Old']);
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('2 of 6 complete');

    CurrentSchool::set($school);
    Section::factory()->for($school)->for($currentYear, 'academicYear')->for($gradeOne)->create(['name' => 'A']);
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('3 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-sections"', '✅', 'data-test="checklist-step-subjects"', '⬜']);

    // Step 4: active subject completes step 4; assigning to only 1 of 2 grades leaves step 5 incomplete.
    CurrentSchool::set($school);
    Subject::factory()->archived()->for($school)->create(['name' => 'Archived Subject']);
    $subject = Subject::factory()->for($school)->create(['name' => 'English']);
    GradeSubject::factory()->for($school)->for($gradeOne)->for($subject)->create();
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('4 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-subjects"', '✅', 'data-test="checklist-step-curriculum"', '⬜']);

    // Step 5: assigning a subject to the remaining active grade completes step 5, leaving step 6 incomplete.
    CurrentSchool::set($school);
    GradeSubject::factory()->for($school)->for($gradeTwo)->for($subject)->create();
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="setup-checklist"', false)
        ->assertSee('5 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-curriculum"', '✅', 'data-test="checklist-step-staff"', '⬜']);

    // Active Parents, disabled staff, and foreign-school staff do not satisfy step 6.
    setupUserForDashboard($school, SchoolRole::Parent);

    $disabledTeacher = User::factory()->create();
    Membership::factory()->disabled()->for($disabledTeacher)->for($school)->create();
    setPermissionsTeamId($school->id);
    $disabledTeacher->assignRole(SchoolRole::Teacher->value);
    setPermissionsTeamId(null);

    $otherSchool = School::factory()->active()->create();
    setupUserForDashboard($otherSchool, SchoolRole::Teacher);

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('5 of 6 complete');

    // Step 6: adding an active non-Owner staff member completes 6 of 6 and hides the checklist.
    setupUserForDashboard($school, SchoolRole::Teacher);

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertDontSee('data-test="setup-checklist"', false)
        ->assertSee('data-test="setup-stats"', false)
        ->assertSeeInOrder(['Active grades', '2', 'Current-year sections', '1', 'Active subjects', '1']);
});

it('scopes quick counts, checklist visibility, and welcome card by role', function () {
    $school = School::factory()->active()->create([
        'name' => 'St. Xavier School',
    ]);

    $teacher = setupUserForDashboard($school, SchoolRole::Teacher);
    $accountant = setupUserForDashboard($school, SchoolRole::Accountant);
    $parent = setupUserForDashboard($school, SchoolRole::Parent);
    $student = setupUserForDashboard($school, SchoolRole::Student);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create(['is_current' => true]);
    $grade = Grade::factory()->for($school)->create();
    Grade::factory()->archived()->for($school)->create();
    Section::factory()->for($school)->for($year, 'academicYear')->for($grade)->create(['name' => 'A']);
    Section::factory()->for($school)->for($year, 'academicYear')->for($grade)->create(['name' => 'B']);
    Subject::factory()->for($school)->create();
    Subject::factory()->for($school)->create();
    Subject::factory()->for($school)->create();
    Subject::factory()->archived()->for($school)->create();
    CurrentSchool::forget();

    foreach ([$teacher, $accountant] as $staffMember) {
        $this->actingAs($staffMember)
            ->get(route('dashboard', ['school' => $school->id]))
            ->assertOk()
            ->assertSee('St. Xavier School')
            ->assertSee('data-test="setup-stats"', false)
            ->assertSeeInOrder(['Active grades', '1', 'Current-year sections', '2', 'Active subjects', '3'])
            ->assertDontSee('data-test="setup-checklist"', false)
            ->assertDontSee('data-test="welcome-card"', false);
    }

    foreach ([$parent, $student] as $portalUser) {
        $this->actingAs($portalUser)
            ->get(route('dashboard', ['school' => $school->id]))
            ->assertOk()
            ->assertSee('St. Xavier School')
            ->assertSee('data-test="welcome-card"', false)
            ->assertSee('Welcome to St. Xavier School')
            ->assertDontSee('data-test="setup-stats"', false)
            ->assertDontSee('data-test="setup-checklist"', false);
    }
});

it('shows checklist links only for setup modules the user is permitted to view', function () {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->active()->create();
    $user = setupUserForDashboard($school);

    setPermissionsTeamId(null);
    $limitedRole = Role::firstOrCreate([
        'name' => 'Calendar Coordinator',
        'guard_name' => 'web',
        'school_id' => null,
    ]);
    $limitedRole->syncPermissions([
        SchoolPermission::AcademicYearsView->value,
        SchoolPermission::AcademicYearsManage->value,
    ]);

    setPermissionsTeamId($school->id);
    $user->assignRole($limitedRole);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="setup-checklist"', false)
        ->assertSee('data-test="checklist-link-academic-year"', false)
        ->assertDontSee('data-test="checklist-link-grades"', false)
        ->assertDontSee('data-test="checklist-link-sections"', false)
        ->assertDontSee('data-test="checklist-link-subjects"', false)
        ->assertDontSee('data-test="checklist-link-curriculum"', false);
});

it('isolates counts and setup checklist state per school', function () {
    $schoolA = School::factory()->active()->create(['name' => 'School A']);
    $schoolB = School::factory()->active()->create(['name' => 'School B']);

    $owner = setupUserForDashboard($schoolA, SchoolRole::Owner);
    setupUserForDashboard($schoolB, SchoolRole::Owner, $owner);
    setupUserForDashboard($schoolB, SchoolRole::Teacher);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create([
        'name' => '2026-27',
        'is_current' => true,
    ]);
    $gradeB = Grade::factory()->for($schoolB)->create();
    $gradeB2 = Grade::factory()->for($schoolB)->create();
    Section::factory()->for($schoolB)->for($yearB, 'academicYear')->for($gradeB)->create(['name' => 'A']);
    Section::factory()->for($schoolB)->for($yearB, 'academicYear')->for($gradeB2)->create(['name' => 'B']);
    $subjectB = Subject::factory()->for($schoolB)->create();
    GradeSubject::factory()->for($schoolB)->for($gradeB)->for($subjectB)->create();
    GradeSubject::factory()->for($schoolB)->for($gradeB2)->for($subjectB)->create();
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('School A')
        ->assertSee('No current academic year')
        ->assertSee('0 of 6 complete')
        ->assertSeeInOrder(['Active grades', '0', 'Current-year sections', '0', 'Active subjects', '0']);

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $schoolB->id]))
        ->assertOk()
        ->assertSee('School B')
        ->assertSee('Current year: 2026-27')
        ->assertDontSee('data-test="setup-checklist"', false)
        ->assertSeeInOrder(['Active grades', '2', 'Current-year sections', '2', 'Active subjects', '1']);
});

it('executes a constant number of database queries regardless of grade count', function () {
    $smallSchool = School::factory()->active()->create();
    $largeSchool = School::factory()->active()->create();

    $smallOwner = setupUserForDashboard($smallSchool, SchoolRole::Owner);
    $largeOwner = setupUserForDashboard($largeSchool, SchoolRole::Owner);

    CurrentSchool::set($smallSchool);
    $smallYear = AcademicYear::factory()->for($smallSchool)->create(['is_current' => true]);
    $smallGrade = Grade::factory()->for($smallSchool)->create();
    Section::factory()->for($smallSchool)->for($smallYear, 'academicYear')->for($smallGrade)->create(['name' => 'A']);
    $smallSubject = Subject::factory()->for($smallSchool)->create();
    GradeSubject::factory()->for($smallSchool)->for($smallGrade)->for($smallSubject)->create();
    CurrentSchool::forget();

    CurrentSchool::set($largeSchool);
    $largeYear = AcademicYear::factory()->for($largeSchool)->create(['is_current' => true]);
    $largeSubject = Subject::factory()->for($largeSchool)->create();
    for ($index = 1; $index <= 15; $index++) {
        $grade = Grade::factory()->for($largeSchool)->create(['sort_order' => $index]);
        Section::factory()->for($largeSchool)->for($largeYear, 'academicYear')->for($grade)->create(['name' => 'A']);
        if ($index < 15) {
            GradeSubject::factory()->for($largeSchool)->for($grade)->for($largeSubject)->create();
        }
    }
    CurrentSchool::forget();

    // Warm caches with an initial render so both measurements capture steady-state runtime queries.
    $this->actingAs($smallOwner)->get(route('dashboard', ['school' => $smallSchool->id]))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($smallOwner)->get(route('dashboard', ['school' => $smallSchool->id]))->assertOk();
    $smallSchoolQueryCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->actingAs($largeOwner)->get(route('dashboard', ['school' => $largeSchool->id]))->assertOk();
    $largeSchoolQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeSchoolQueryCount)
        ->toBe($smallSchoolQueryCount)
        ->toBeLessThanOrEqual(15);
});

it('shows ends today when a trial ends later today in India time even across UTC midnight', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Kolkata'));

    try {
        $sameDayTrialSchool = School::factory()->create([
            'trial_ends_at' => now('Asia/Kolkata')->addHours(3)->utc(),
        ]);
        $owner = setupUserForDashboard($sameDayTrialSchool, SchoolRole::Owner);

        $this->actingAs($owner)
            ->get(route('dashboard', ['school' => $sameDayTrialSchool->id]))
            ->assertOk()
            ->assertSee('data-test="trial-banner"', false)
            ->assertSee('Your trial ends today')
            ->assertDontSee('ends in 1 day');

        // 20:00 UTC on Nov 26 is 01:30 IST on Nov 27: still ends today in Asia/Kolkata.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 20:00:00', 'UTC'));

        $istCrossMidnightSchool = School::factory()->create([
            'trial_ends_at' => CarbonImmutable::parse('2026-11-27 23:59:59', 'Asia/Kolkata')->utc(),
        ]);
        $secondOwner = setupUserForDashboard($istCrossMidnightSchool, SchoolRole::Owner);

        $this->actingAs($secondOwner)
            ->get(route('dashboard', ['school' => $istCrossMidnightSchool->id]))
            ->assertOk()
            ->assertSee('Your trial ends today')
            ->assertDontSee('ends in 1 day');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('keeps the curriculum checklist step incomplete when a grade only has archived subjects', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserForDashboard($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create(['is_current' => true]);
    $grade = Grade::factory()->for($school)->create();
    Section::factory()->for($school)->for($year, 'academicYear')->for($grade)->create(['name' => 'A']);

    Subject::factory()->for($school)->create(['name' => 'Mathematics']);
    $archivedSubject = Subject::factory()->for($school)->create(['name' => 'Old Sanskrit']);
    GradeSubject::factory()->for($school)->for($grade)->for($archivedSubject)->create();

    $archivedSubject->archived_at = now();
    $archivedSubject->save();
    CurrentSchool::forget();

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="setup-checklist"', false)
        ->assertSee('4 of 6 complete')
        ->assertSeeInOrder(['data-test="checklist-step-subjects"', '✅', 'data-test="checklist-step-curriculum"', '⬜']);
});

it('queries the current academic year at most once when a school has no current year', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserForDashboard($school, SchoolRole::Owner);

    $this->actingAs($owner)->get(route('dashboard', ['school' => $school->id]))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($owner)->get(route('dashboard', ['school' => $school->id]))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $academicYearQueries = array_filter(
        $queries,
        static fn (array $entry): bool => str_contains($entry['query'], 'academic_years'),
    );

    expect($academicYearQueries)->toHaveCount(1);
});

it('renders a clean landing page and sidebar without starter-kit links', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee('Log in')
        ->assertSee(route('login'), false)
        ->assertSee('Register your school')
        ->assertSee(route('schools.register'), false)
        ->assertDontSee('livewire-starter-kit')
        ->assertDontSee('starter-kits')
        ->assertDontSee('https://laravel.com/docs', false)
        ->assertDontSee('https://laracasts.com', false)
        ->assertDontSee('Documentation')
        ->assertDontSee('Repository');

    $school = School::factory()->active()->create();
    $owner = setupUserForDashboard($school, SchoolRole::Owner);

    $this->actingAs($owner)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="staff-sidebar-group"', false)
        ->assertSee(route('staff.index', ['school' => $school->id]), false)
        ->assertDontSee('livewire-starter-kit')
        ->assertDontSee('starter-kits')
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation');

    foreach ([SchoolRole::Teacher, SchoolRole::Accountant, SchoolRole::Parent, SchoolRole::Student] as $nonStaffViewer) {
        $viewer = setupUserForDashboard($school, $nonStaffViewer);

        $this->actingAs($viewer)
            ->get(route('dashboard', ['school' => $school->id]))
            ->assertOk()
            ->assertDontSee('data-test="staff-sidebar-group"', false)
            ->assertDontSee(route('staff.index', ['school' => $school->id]), false);
    }
});
