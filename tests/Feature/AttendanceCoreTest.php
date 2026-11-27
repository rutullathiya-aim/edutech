<?php

use App\Actions\Attendance\ResolveRegister;
use App\Actions\Attendance\TakeAttendance;
use App\Actions\Attendance\UpdateWeeklyOffDays;
use App\Actions\Students\ChangeSection;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Events\AttendanceTaken;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Holiday;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    test()->seed(PermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    DB::disableQueryLog();
    DB::flushQueryLog();
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::flushQueryLog();
    CarbonImmutable::setTestNow();
    CurrentSchool::forget();
    setPermissionsTeamId(null);
});

/**
 * Provision an active or disabled school user with the given role for attendance core tests.
 */
function createAttendanceMember(
    School $school,
    SchoolRole $role,
    bool $active = true,
    ?string $name = null,
): User {
    $user = User::factory()->create($name !== null ? ['name' => $name] : []);

    Membership::factory()
        ->state(['status' => $active ? MembershipStatus::Active : MembershipStatus::Disabled])
        ->for($user)
        ->for($school)
        ->create();

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return $user;
}

/**
 * Create a school academic year, grade, and two sections (5-A, 5-B) for attendance tests.
 *
 * @return array{year: AcademicYear, grade: Grade, sectionA: Section, sectionB: Section}
 */
function createAttendanceStructure(School $school, ?User $teacherA = null, ?User $teacherB = null): array
{
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 5,
    ]);

    $sectionA = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'B']);

    if ($teacherA instanceof User) {
        $sectionA->class_teacher_user_id = $teacherA->id;
        $sectionA->save();
    }

    if ($teacherB instanceof User) {
        $sectionB->class_teacher_user_id = $teacherB->id;
        $sectionB->save();
    }

    return compact('year', 'grade', 'sectionA', 'sectionB');
}

/**
 * Create an enrollment on a section for attendance register and marking tests.
 */
function enrollAttendanceStudent(
    School $school,
    AcademicYear $year,
    Section $section,
    string $name,
    ?int $rollNumber = null,
    string $enrolledOn = '2026-04-01',
    EnrollmentStatus $status = EnrollmentStatus::Active,
    ?string $leftOn = null,
): Enrollment {
    $student = Student::factory()->for($school)->create(['name' => $name]);

    return Enrollment::factory()->create([
        'school_id' => $school->id,
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
        'section_id' => $section->id,
        'roll_number' => $rollNumber,
        'enrolled_on' => $enrolledOn,
        'status' => $status,
        'left_on' => $leftOn,
    ]);
}

/**
 * Capture and return executed SQL queries inside the callback.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return array{result: T, count: int}
 */
function countQueriesDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $result = $callback();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return [
        'result' => $result,
        'count' => count($queries),
    ];
}

test('AttendanceStatus enum exposes exact labels, short labels, presence weights, and present helper', function () {
    expect(AttendanceStatus::Present->label())->toBe('Present')
        ->and(AttendanceStatus::Present->shortLabel())->toBe('P')
        ->and(AttendanceStatus::Present->presenceWeight())->toBe(1.0)
        ->and(AttendanceStatus::Present->countsAsPresent())->toBeTrue()
        ->and(AttendanceStatus::Absent->label())->toBe('Absent')
        ->and(AttendanceStatus::Absent->shortLabel())->toBe('A')
        ->and(AttendanceStatus::Absent->presenceWeight())->toBe(0.0)
        ->and(AttendanceStatus::Absent->countsAsPresent())->toBeFalse()
        ->and(AttendanceStatus::Late->label())->toBe('Late')
        ->and(AttendanceStatus::Late->shortLabel())->toBe('L')
        ->and(AttendanceStatus::Late->presenceWeight())->toBe(1.0)
        ->and(AttendanceStatus::Late->countsAsPresent())->toBeTrue()
        ->and(AttendanceStatus::HalfDay->label())->toBe('Half day')
        ->and(AttendanceStatus::HalfDay->shortLabel())->toBe('H')
        ->and(AttendanceStatus::HalfDay->presenceWeight())->toBe(0.5)
        ->and(AttendanceStatus::HalfDay->countsAsPresent())->toBeTrue()
        ->and(AttendanceStatus::Leave->label())->toBe('Leave')
        ->and(AttendanceStatus::Leave->shortLabel())->toBe('LV')
        ->and(AttendanceStatus::Leave->presenceWeight())->toBe(0.0)
        ->and(AttendanceStatus::Leave->countsAsPresent())->toBeFalse();
});

test('attendance schema enforces unique section/date sessions, unique enrollment/date records, composite FKs, cascade deletes, and fillable guards', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $ownerA = createAttendanceMember($schoolA, SchoolRole::Owner);
    $ownerB = createAttendanceMember($schoolB, SchoolRole::Owner);

    $structureA = createAttendanceStructure($schoolA);
    $structureB = createAttendanceStructure($schoolB);

    CurrentSchool::set($schoolA);
    $enrollmentA = enrollAttendanceStudent($schoolA, $structureA['year'], $structureA['sectionA'], 'Aarav Sharma', 1);

    // Non-fillable summary/version/actor fields throw MassAssignmentException on AttendanceSession.
    expect(fn () => AttendanceSession::create([
        'academic_year_id' => $structureA['year']->id,
        'section_id' => $structureA['sectionA']->id,
        'date' => '2026-11-26',
        'present_count' => 99,
    ]))->toThrow(MassAssignmentException::class);

    expect(fn () => AttendanceSession::create([
        'academic_year_id' => $structureA['year']->id,
        'section_id' => $structureA['sectionA']->id,
        'date' => '2026-11-26',
        'version' => 5,
    ]))->toThrow(MassAssignmentException::class);

    // Valid session creation.
    $sessionA1 = AttendanceSession::factory()->create([
        'school_id' => $schoolA->id,
        'academic_year_id' => $structureA['year']->id,
        'section_id' => $structureA['sectionA']->id,
        'date' => '2026-11-26',
        'taken_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ]);

    // Duplicate session for same school + section + date fails unique key.
    expect(fn () => AttendanceSession::factory()->create([
        'school_id' => $schoolA->id,
        'academic_year_id' => $structureA['year']->id,
        'section_id' => $structureA['sectionA']->id,
        'date' => '2026-11-26',
        'taken_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ]))->toThrow(QueryException::class);

    // Cross-school (school_id, academic_year_id, section_id) rejected by composite FK.
    expect(fn () => AttendanceSession::factory()->create([
        'school_id' => $schoolA->id,
        'academic_year_id' => $structureB['year']->id,
        'section_id' => $structureB['sectionA']->id,
        'date' => '2026-11-26',
        'taken_by' => $ownerB->id,
        'updated_by' => $ownerB->id,
    ]))->toThrow(QueryException::class);

    // Second section session on same date succeeds, but marking same enrollment twice on one date fails unique key.
    $sessionA2 = AttendanceSession::factory()->create([
        'school_id' => $schoolA->id,
        'academic_year_id' => $structureA['year']->id,
        'section_id' => $structureA['sectionB']->id,
        'date' => '2026-11-26',
        'taken_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ]);

    AttendanceRecord::factory()->create([
        'school_id' => $schoolA->id,
        'attendance_session_id' => $sessionA1->id,
        'enrollment_id' => $enrollmentA->id,
        'date' => '2026-11-26',
        'status' => AttendanceStatus::Present,
    ]);

    expect(fn () => AttendanceRecord::factory()->create([
        'school_id' => $schoolA->id,
        'attendance_session_id' => $sessionA2->id,
        'enrollment_id' => $enrollmentA->id,
        'date' => '2026-11-26',
        'status' => AttendanceStatus::Present,
    ]))->toThrow(QueryException::class);

    // Deleting session cascades to its attendance_records.
    expect(AttendanceRecord::query()->where('attendance_session_id', $sessionA1->id)->count())->toBe(1);
    $sessionA1->delete();
    expect(AttendanceRecord::query()->where('attendance_session_id', $sessionA1->id)->count())->toBe(0);
});

test('AttendanceSessionPolicy allows Owner/Admin past dates and restricts Teacher to today on assigned sections', function (SchoolRole $role, string $scenario, bool $expectedAllowed) {
    CarbonImmutable::setTestNow('2026-11-26 10:00:00 Asia/Kolkata');

    $school = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $otherTeacher = createAttendanceMember($school, SchoolRole::Teacher, true, 'Other Teacher');
    $assignedTeacher = $role === SchoolRole::Teacher && $scenario === 'own_section_today'
        ? createAttendanceMember($school, SchoolRole::Teacher, true, 'Assigned Teacher')
        : createAttendanceMember($school, SchoolRole::Teacher, true, 'Section Teacher');

    $structure = createAttendanceStructure($school, $assignedTeacher, $otherTeacher);

    $closedYear = AcademicYear::factory()->for($school)->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);
    $closedSection = Section::factory()->for($school)->for($closedYear)->for($structure['grade'])->create([
        'name' => 'C',
        'class_teacher_user_id' => $assignedTeacher->id,
    ]);
    $closedYear->closed_at = now();
    $closedYear->save();

    // Holiday on Wednesday 2026-11-18.
    Holiday::factory()->for($school)->for($structure['year'])->create([
        'date' => '2026-11-18',
        'name' => 'Mid-week Holiday',
    ]);

    $actor = match ($role) {
        SchoolRole::Teacher => match ($scenario) {
            'own_section_today', 'past_date_teacher' => $assignedTeacher,
            default => createAttendanceMember($school, SchoolRole::Teacher),
        },
        default => createAttendanceMember($school, $role),
    };

    if ($scenario === 'disabled_teacher') {
        $actor = createAttendanceMember($school, SchoolRole::Teacher, false, 'Disabled Class Teacher');
        $structure['sectionA']->class_teacher_user_id = $actor->id;
        $structure['sectionA']->save();
    }

    if ($scenario === 'past_date_teacher') {
        $structure['sectionA']->class_teacher_user_id = $actor->id;
        $structure['sectionA']->save();
    }

    $targetSection = $scenario === 'closed_year' ? $closedSection : $structure['sectionA'];

    $targetDate = match ($scenario) {
        'own_section_today', 'other_section_teacher', 'disabled_teacher', 'denied_role' => '2026-11-26',
        'past_date_admin', 'past_date_teacher' => '2026-11-24',
        'future_date' => '2026-11-27',
        'sunday_weekly_off' => '2026-11-22',
        'holiday_date' => '2026-11-18',
        'closed_year' => '2025-11-20',
        'outside_year_before' => '2026-03-20',
        'outside_year_after' => '2027-04-05',
        default => '2026-11-26',
    };

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    expect(Gate::forUser($actor)->allows('mark', [AttendanceSession::class, $targetSection, CarbonImmutable::parse($targetDate)]))
        ->toBe($expectedAllowed);
})->with([
    'owner today' => [SchoolRole::Owner, 'own_section_today', true],
    'owner past date in open year' => [SchoolRole::Owner, 'past_date_admin', true],
    'admin past date in open year' => [SchoolRole::Admin, 'past_date_admin', true],
    'teacher own section today' => [SchoolRole::Teacher, 'own_section_today', true],
    'teacher own section past date denied' => [SchoolRole::Teacher, 'past_date_teacher', false],
    'teacher unassigned section denied' => [SchoolRole::Teacher, 'other_section_teacher', false],
    'disabled class teacher denied' => [SchoolRole::Teacher, 'disabled_teacher', false],
    'accountant denied' => [SchoolRole::Accountant, 'denied_role', false],
    'parent denied' => [SchoolRole::Parent, 'denied_role', false],
    'student denied' => [SchoolRole::Student, 'denied_role', false],
    'admin future date denied' => [SchoolRole::Admin, 'future_date', false],
    'admin weekly off denied' => [SchoolRole::Admin, 'sunday_weekly_off', false],
    'admin holiday denied' => [SchoolRole::Admin, 'holiday_date', false],
    'owner closed year denied' => [SchoolRole::Owner, 'closed_year', false],
    'owner outside year starts_on denied' => [SchoolRole::Owner, 'outside_year_before', false],
    'owner outside year ends_on denied' => [SchoolRole::Owner, 'outside_year_after', false],
]);

test('AttendanceSessionPolicy calculates today in the school timezone across UTC day boundaries', function () {
    // 23:30 UTC on 2026-11-26 = 05:00 IST on Friday 2026-11-27.
    CarbonImmutable::setTestNow('2026-11-26 23:30:00 UTC');

    $istSchool = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $utcSchool = School::factory()->active()->create(['timezone' => 'UTC']);

    $istTeacher = createAttendanceMember($istSchool, SchoolRole::Teacher);
    $istStructure = createAttendanceStructure($istSchool, $istTeacher);

    $utcTeacher = createAttendanceMember($utcSchool, SchoolRole::Teacher);
    $utcStructure = createAttendanceStructure($utcSchool, $utcTeacher);

    // IST school allows 2026-11-27 (today in IST) and denies 2026-11-26 (yesterday in IST) for Teacher.
    CurrentSchool::set($istSchool);
    setPermissionsTeamId($istSchool->id);

    expect(Gate::forUser($istTeacher)->allows('mark', [AttendanceSession::class, $istStructure['sectionA'], CarbonImmutable::parse('2026-11-27')]))->toBeTrue()
        ->and(Gate::forUser($istTeacher)->allows('mark', [AttendanceSession::class, $istStructure['sectionA'], CarbonImmutable::parse('2026-11-26')]))->toBeFalse();

    // UTC school allows 2026-11-26 (today in UTC) and denies 2026-11-27 (future in UTC).
    CurrentSchool::set($utcSchool);
    setPermissionsTeamId($utcSchool->id);

    expect(Gate::forUser($utcTeacher)->allows('mark', [AttendanceSession::class, $utcStructure['sectionA'], CarbonImmutable::parse('2026-11-26')]))->toBeTrue()
        ->and(Gate::forUser($utcTeacher)->allows('mark', [AttendanceSession::class, $utcStructure['sectionA'], CarbonImmutable::parse('2026-11-27')]))->toBeFalse();
});

test('changing Saturday to weekly off lets Admin edit an existing Saturday session while refusing new Saturday sessions', function () {
    CarbonImmutable::setTestNow('2026-11-28 11:00:00 Asia/Kolkata'); // Saturday 28 Nov 2026

    $school = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $admin = createAttendanceMember($school, SchoolRole::Admin, true, 'Admin User');
    $teacher = createAttendanceMember($school, SchoolRole::Teacher, true, 'Class Teacher');
    $structure = createAttendanceStructure($school, $teacher);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $e1 = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Aarav Sharma', 1);
    enrollAttendanceStudent($school, $structure['year'], $structure['sectionB'], 'Bhavna Patel', 1);

    // Before making Saturday a weekly off, record Saturday 2026-11-21 session for sectionA.
    $takeAttendance = app(TakeAttendance::class);
    $saturdayExisting = CarbonImmutable::parse('2026-11-21');
    $saturdayOther = CarbonImmutable::parse('2026-11-14');

    $session = $takeAttendance->handle($admin, $structure['sectionA'], $saturdayExisting, [
        $e1->id => AttendanceStatus::Present->value,
    ], 0);

    // School later makes Saturday + Sunday weekly off days ([6, 7]).
    app(UpdateWeeklyOffDays::class)->handle($admin, [6, 7]);
    $school->refresh();
    CurrentSchool::set($school);

    // Admin CAN open and edit the already-existing Saturday session on sectionA (and view it),
    // while Teacher is denied and creating a NEW session on another Saturday or another section is refused.
    expect(Gate::forUser($admin)->allows('view', $session))->toBeTrue()
        ->and(Gate::forUser($teacher)->allows('view', $session))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('mark', [AttendanceSession::class, $structure['sectionA'], $saturdayExisting]))->toBeTrue()
        ->and(Gate::forUser($teacher)->allows('mark', [AttendanceSession::class, $structure['sectionA'], $saturdayExisting]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('mark', [AttendanceSession::class, $structure['sectionA'], $saturdayOther]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('mark', [AttendanceSession::class, $structure['sectionB'], $saturdayExisting]))->toBeFalse();

    // Admin actually edits the existing Saturday session cleanly.
    $edited = $takeAttendance->handle($admin, $structure['sectionA'], $saturdayExisting, [
        $e1->id => AttendanceStatus::Late->value,
    ], $session->version);

    expect($edited->version)->toBe(2)
        ->and($edited->late_count)->toBe(1)
        ->and($edited->present_count)->toBe(0);

    // Creating a new session on another Saturday throws AuthorizationException.
    expect(fn () => $takeAttendance->handle($admin, $structure['sectionA'], $saturdayOther, [
        $e1->id => AttendanceStatus::Present->value,
    ], 0))->toThrow(AuthorizationException::class);
});

test('TakeAttendance saves records, calculates counts, validates completeness/membership/remarks, enforces optimistic locks, fires AttendanceTaken once after commit, avoids PII in logs, and uses constant query budget', function () {
    CarbonImmutable::setTestNow('2026-11-26 09:15:00 Asia/Kolkata');

    $school = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $otherSchool = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);

    $teacher = createAttendanceMember($school, SchoolRole::Teacher, true, 'Priya Teacher');
    $admin = createAttendanceMember($school, SchoolRole::Admin, true, 'Ramesh Admin');
    $otherOwner = createAttendanceMember($otherSchool, SchoolRole::Owner);

    $structure = createAttendanceStructure($school, $teacher);
    $otherStructure = createAttendanceStructure($otherSchool, $otherOwner);

    CurrentSchool::set($otherSchool);
    $otherSchoolEnrollment = enrollAttendanceStudent($otherSchool, $otherStructure['year'], $otherStructure['sectionA'], 'Foreign Student', 1);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $ePresent = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Aarav Sharma', 1);
    $eAbsent = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Diya Patel', 2);
    $eLate = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Kabir Verma', 3);
    $eHalf = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Meera Nair', 4);
    $eLeave = enrollAttendanceStudent($school, $structure['year'], $structure['sectionA'], 'Rohan Joshi', 5);
    $sectionBEnrollment = enrollAttendanceStudent($school, $structure['year'], $structure['sectionB'], 'Sana Khan', 1);

    $today = CarbonImmutable::parse('2026-11-26');
    $takeAttendance = app(TakeAttendance::class);

    // 1. Validation failures before saving: incomplete register, other-section ID, other-school ID, bad status, bad remarks.
    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
    ], 0))->toThrow(ValidationException::class);

    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
        $eLate->id => 'late',
        $eHalf->id => 'half_day',
        $eLeave->id => 'leave',
        $sectionBEnrollment->id => 'present',
    ], 0))->toThrow(ValidationException::class);

    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
        $eLate->id => 'late',
        $eHalf->id => 'half_day',
        $eLeave->id => 'leave',
        $otherSchoolEnrollment->id => 'present',
    ], 0))->toThrow(ValidationException::class);

    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
        $eLate->id => 'late',
        $eHalf->id => 'half_day',
        $eLeave->id => 'invalid_status',
    ], 0))->toThrow(ValidationException::class);

    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
        $eLate->id => 'late',
        $eHalf->id => 'half_day',
        $eLeave->id => 'leave',
    ], 0, [$eLate->id => '=HYPERLINK("https://evil.test")']))->toThrow(ValidationException::class);

    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => 'present',
        $eAbsent->id => 'absent',
        $eLate->id => 'late',
        $eHalf->id => 'half_day',
        $eLeave->id => 'leave',
    ], 0, [$eLate->id => str_repeat('a', 101)]))->toThrow(ValidationException::class);

    expect(AttendanceSession::query()->count())->toBe(0);

    // 2. Rolled-back transaction must NOT fire AttendanceTaken after commit.
    Event::fake([AttendanceTaken::class]);

    try {
        DB::transaction(function () use ($takeAttendance, $teacher, $structure, $today, $ePresent, $eAbsent, $eLate, $eHalf, $eLeave): void {
            $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
                $ePresent->id => 'present',
                $eAbsent->id => 'absent',
                $eLate->id => 'late',
                $eHalf->id => 'half_day',
                $eLeave->id => 'leave',
            ], 0);

            throw new RuntimeException('Simulated rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(AttendanceTaken::class);
    expect(AttendanceSession::query()->count())->toBe(0);

    // 3. Successful first save creates 1 session + 5 records, exact counts, version 1, fires AttendanceTaken once, and writes 1 clean audit log.
    $activityBefore = Activity::query()->count();

    $session = $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => AttendanceStatus::Present->value,
        $eAbsent->id => AttendanceStatus::Absent->value,
        $eLate->id => AttendanceStatus::Late->value,
        $eHalf->id => AttendanceStatus::HalfDay->value,
        $eLeave->id => AttendanceStatus::Leave->value,
    ], 0, [
        $eLate->id => 'Arrived at 9:20',
    ]);

    expect($session->version)->toBe(1)
        ->and($session->taken_by)->toBe($teacher->id)
        ->and($session->updated_by)->toBe($teacher->id)
        ->and($session->present_count)->toBe(1)
        ->and($session->absent_count)->toBe(1)
        ->and($session->late_count)->toBe(1)
        ->and($session->half_day_count)->toBe(1)
        ->and($session->leave_count)->toBe(1)
        ->and(AttendanceRecord::query()->where('attendance_session_id', $session->id)->count())->toBe(5);

    Event::assertDispatchedTimes(AttendanceTaken::class, 1);
    Event::assertDispatched(AttendanceTaken::class, static function (AttendanceTaken $event) use ($school, $session, $eAbsent): bool {
        return $event->schoolId === $school->id
            && $event->attendanceSessionId === $session->id
            && $event->absentEnrollmentIds === [$eAbsent->id];
    });

    $firstLog = Activity::query()->latest('id')->firstOrFail();
    expect(Activity::query()->count())->toBe($activityBefore + 1)
        ->and($firstLog->event)->toBe('attendance_taken');

    $serializedFirstLog = json_encode($firstLog->toArray()) ?: '';
    expect($serializedFirstLog)
        ->not->toContain('Aarav Sharma', 'Diya Patel', 'Kabir Verma', 'Meera Nair', 'Rohan Joshi', 'Arrived at 9:20');

    // 4. Edit updates session (Admin at 10:00), logs exact changed records, writes no student names, and never re-fires AttendanceTaken.
    CarbonImmutable::setTestNow('2026-11-26 10:05:00 Asia/Kolkata');

    $updatedSession = $takeAttendance->handle($admin, $structure['sectionA'], $today, [
        $ePresent->id => AttendanceStatus::Present->value,
        $eAbsent->id => AttendanceStatus::Late->value, // Changed: absent -> late
        $eLate->id => AttendanceStatus::Late->value,
        $eHalf->id => AttendanceStatus::Present->value, // Changed: half_day -> present
        $eLeave->id => AttendanceStatus::Leave->value,
    ], 1);

    expect($updatedSession->version)->toBe(2)
        ->and($updatedSession->taken_by)->toBe($teacher->id)
        ->and($updatedSession->updated_by)->toBe($admin->id)
        ->and($updatedSession->present_count)->toBe(2)
        ->and($updatedSession->absent_count)->toBe(0)
        ->and($updatedSession->late_count)->toBe(2)
        ->and($updatedSession->half_day_count)->toBe(0)
        ->and($updatedSession->leave_count)->toBe(1);

    Event::assertDispatchedTimes(AttendanceTaken::class, 1);

    $editLog = Activity::query()->latest('id')->firstOrFail();
    expect(Activity::query()->count())->toBe($activityBefore + 2)
        ->and($editLog->event)->toBe('attendance_updated')
        ->and($editLog->properties->get('changes'))->toBe([
            $eAbsent->id => ['absent', 'late'],
            $eHalf->id => ['half_day', 'present'],
        ]);

    $serializedEditLog = json_encode($editLog->toArray()) ?: '';
    expect($serializedEditLog)
        ->not->toContain('Aarav Sharma', 'Diya Patel', 'Kabir Verma', 'Meera Nair', 'Rohan Joshi');

    // 5. Stale version (version 1 after version moved to 2) fails cleanly with updater name/time and changes nothing.
    expect(fn () => $takeAttendance->handle($teacher, $structure['sectionA'], $today, [
        $ePresent->id => AttendanceStatus::Absent->value,
        $eAbsent->id => AttendanceStatus::Absent->value,
        $eLate->id => AttendanceStatus::Absent->value,
        $eHalf->id => AttendanceStatus::Absent->value,
        $eLeave->id => AttendanceStatus::Absent->value,
    ], 1))->toThrow(ValidationException::class, 'Attendance for this class was updated by Ramesh Admin');

    expect($updatedSession->fresh()->version)->toBe(2)
        ->and($updatedSession->fresh()->present_count)->toBe(2)
        ->and(Activity::query()->count())->toBe($activityBefore + 2);
});

test('TakeAttendance and ResolveRegister use constant query budgets for 10 vs 60 students', function () {
    CarbonImmutable::setTestNow('2026-11-26 09:00:00 Asia/Kolkata');

    $school = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $owner = createAttendanceMember($school, SchoolRole::Owner);
    $structure = createAttendanceStructure($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $smallSection = $structure['sectionA'];
    $largeSection = $structure['sectionB'];

    $smallStatuses = [];
    foreach (range(1, 10) as $i) {
        $enrollment = enrollAttendanceStudent($school, $structure['year'], $smallSection, "Small Student {$i}", $i);
        $smallStatuses[$enrollment->id] = AttendanceStatus::Present->value;
    }

    $largeStatuses = [];
    foreach (range(1, 60) as $i) {
        $enrollment = enrollAttendanceStudent($school, $structure['year'], $largeSection, "Large Student {$i}", $i);
        $largeStatuses[$enrollment->id] = AttendanceStatus::Present->value;
    }

    $date = CarbonImmutable::parse('2026-11-26');
    $resolveRegister = app(ResolveRegister::class);
    $takeAttendance = app(TakeAttendance::class);

    // Unmarked ResolveRegister = strictly 2 queries for both 10 and 60 students.
    $unmarkedSmall = countQueriesDuring(fn () => $resolveRegister->handle($smallSection, $date));
    $unmarkedLarge = countQueriesDuring(fn () => $resolveRegister->handle($largeSection, $date));

    expect($unmarkedSmall['count'])->toBe(2)
        ->and($unmarkedLarge['count'])->toBe(2)
        ->and($unmarkedSmall['result']->rows)->toHaveCount(10)
        ->and($unmarkedLarge['result']->rows)->toHaveCount(60);

    // Warm Spatie's permission cache beforehand so both 10-student and 60-student saves measure steady-state queries.
    Gate::forUser($owner)->allows('viewAny', AttendanceSession::class);

    // TakeAttendance runs the exact same query count for 10 vs 60 students.
    $saveSmall = countQueriesDuring(fn () => $takeAttendance->handle($owner, $smallSection, $date, $smallStatuses, 0));
    $saveLarge = countQueriesDuring(fn () => $takeAttendance->handle($owner, $largeSection, $date, $largeStatuses, 0));

    expect($saveLarge['count'])->toBe($saveSmall['count']);

    // Marked ResolveRegister = still strictly 2 queries for both 10 and 60 students.
    $markedSmall = countQueriesDuring(fn () => $resolveRegister->handle($smallSection, $date));
    $markedLarge = countQueriesDuring(fn () => $resolveRegister->handle($largeSection, $date));

    expect($markedSmall['count'])->toBe(2)
        ->and($markedLarge['count'])->toBe(2)
        ->and($markedSmall['result']->version)->toBe(1)
        ->and($markedLarge['result']->version)->toBe(1);
});

test('ResolveRegister handles roll-number nulls-last ordering, admission/exit dates, and mid-day section transfers without double marking', function () {
    CarbonImmutable::setTestNow('2026-11-26 12:00:00 Asia/Kolkata');

    $school = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $admin = createAttendanceMember($school, SchoolRole::Admin);
    $structure = createAttendanceStructure($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $sectionA = $structure['sectionA'];
    $sectionB = $structure['sectionB'];
    $year = $structure['year'];

    // Ordering: roll 2 before roll 10, then null roll numbers sorted alphabetically (Ananya before Zara).
    $rollTen = enrollAttendanceStudent($school, $year, $sectionA, 'Kabir Roll Ten', 10, '2026-04-01');
    $rollNullZ = enrollAttendanceStudent($school, $year, $sectionA, 'Zara Unrolled', null, '2026-04-01');
    $rollTwo = enrollAttendanceStudent($school, $year, $sectionA, 'Aarav Roll Two', 2, '2026-04-01');
    $rollNullA = enrollAttendanceStudent($school, $year, $sectionA, 'Ananya Unrolled', null, '2026-04-01');

    // Boundary dates relative to 2026-11-24:
    // Enrolled after 2026-11-24 -> excluded on 24th, included on 25th+.
    $futureJoiner = enrollAttendanceStudent($school, $year, $sectionA, 'Dev Later Joiner', 15, '2026-11-25');
    // Left on 2026-11-24 -> excluded on 24th (left_on <= date).
    $leftOnDay = enrollAttendanceStudent($school, $year, $sectionA, 'Esha Left On Day', 16, '2026-04-01', EnrollmentStatus::Left, '2026-11-24');
    // Left on 2026-11-25 -> included on 24th (left_on > date), excluded on 25th.
    $leftNextDay = enrollAttendanceStudent($school, $year, $sectionA, 'Farhan Left Next Day', 17, '2026-04-01', EnrollmentStatus::Left, '2026-11-25');

    $resolveRegister = app(ResolveRegister::class);
    $takeAttendance = app(TakeAttendance::class);
    $changeSection = app(ChangeSection::class);

    $nov24 = CarbonImmutable::parse('2026-11-24');
    $nov25 = CarbonImmutable::parse('2026-11-25');

    $registerNov24 = $resolveRegister->handle($sectionA, $nov24);
    expect($registerNov24->rows->pluck('studentName')->all())->toBe([
        'Aarav Roll Two',
        'Kabir Roll Ten',
        'Farhan Left Next Day',
        'Ananya Unrolled',
        'Zara Unrolled',
    ]);

    // Take attendance on Nov 24 for sectionA (includes Aarav Roll Two, excludes Farhan on Nov 25).
    $nov24Statuses = [];
    foreach ($registerNov24->rows as $row) {
        $nov24Statuses[$row->enrollmentId] = AttendanceStatus::Present->value;
    }
    $takeAttendance->handle($admin, $sectionA, $nov24, $nov24Statuses, 0);

    // Mid-day transfer on Nov 24: Aarav moves from 5-A to 5-B.
    $changeSection->handle($admin, $rollTwo, $sectionB, 1);

    // 5-A on Nov 24 STILL retains Aarav's saved record from that session.
    $sectionANov24AfterTransfer = $resolveRegister->handle($sectionA, $nov24);
    expect($sectionANov24AfterTransfer->rows->pluck('enrollmentId')->all())->toContain($rollTwo->id);

    // 5-B on Nov 24 MUST NOT include Aarav (never marked in both sections on the same day).
    $sectionBNov24 = $resolveRegister->handle($sectionB, $nov24);
    expect($sectionBNov24->rows->pluck('enrollmentId')->all())->not->toContain($rollTwo->id);

    // On Nov 25, Aarav belongs to 5-B (not 5-A), Dev Later Joiner appears in 5-A, and Farhan (left_on = Nov 25) is excluded.
    $sectionANov25 = $resolveRegister->handle($sectionA, $nov25);
    $sectionBNov25 = $resolveRegister->handle($sectionB, $nov25);

    expect($sectionANov25->rows->pluck('enrollmentId')->all())
        ->toContain($futureJoiner->id)
        ->not->toContain($rollTwo->id, $leftOnDay->id, $leftNextDay->id);

    expect($sectionBNov25->rows->pluck('enrollmentId')->all())->toBe([$rollTwo->id]);
});
