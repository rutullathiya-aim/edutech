<?php

use App\Actions\Attendance\AssignClassTeacher;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Livewire\Setup\Sections as SectionsScreen;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
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
 * Provision a user with an active or disabled membership and optional school role.
 */
function createClassTeacherMember(
    School $school,
    SchoolRole $role,
    bool $active = true,
    ?string $name = null,
): array {
    $user = User::factory()->create($name !== null ? ['name' => $name] : []);

    $membership = Membership::factory()
        ->state(['status' => $active ? MembershipStatus::Active : MembershipStatus::Disabled])
        ->for($user)
        ->for($school)
        ->create();

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return [$user, $membership];
}

test('sections composite FK rejects foreign-school class teachers, blocks deleting referenced memberships, and guards mass assignment', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    [$foreignTeacher] = createClassTeacherMember($schoolB, SchoolRole::Teacher);
    [$localTeacher, $localMembership] = createClassTeacherMember($schoolA, SchoolRole::Teacher);

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->current()->create();
    $gradeA = Grade::factory()->for($schoolA)->create();
    $sectionA = Section::factory()->for($schoolA)->for($yearA)->for($gradeA)->create(['name' => 'A']);

    // Mass-assigning class_teacher_user_id must throw MassAssignmentException (Model::preventSilentlyDiscardingAttributes is active).
    expect(fn () => Section::create([
        'academic_year_id' => $yearA->id,
        'grade_id' => $gradeA->id,
        'name' => 'B',
        'class_teacher_user_id' => $localTeacher->id,
    ]))->toThrow(MassAssignmentException::class);

    // Direct DB write with foreign teacher must be rejected by sections_class_teacher_fk.
    $sectionA->class_teacher_user_id = $foreignTeacher->id;
    expect(fn () => $sectionA->save())->toThrow(QueryException::class);

    // Valid local teacher succeeds, and deleting that referenced membership is blocked by restrictOnDelete().
    $sectionA->class_teacher_user_id = $localTeacher->id;
    $sectionA->save();

    expect(fn () => $localMembership->delete())->toThrow(QueryException::class);
});

test('AssignClassTeacher enforces attendance.manage, active staff roles, closed years, doubling up, and explicit audit logs', function () {
    $school = School::factory()->active()->create();
    $otherSchool = School::factory()->active()->create();

    [$owner] = createClassTeacherMember($school, SchoolRole::Owner);
    [$admin] = createClassTeacherMember($school, SchoolRole::Admin);
    [$teacherA] = createClassTeacherMember($school, SchoolRole::Teacher, true, 'Priya Teacher');
    [$teacherB] = createClassTeacherMember($school, SchoolRole::Teacher, true, 'Ravi Teacher');
    [$disabledTeacher] = createClassTeacherMember($school, SchoolRole::Teacher, false, 'Disabled Staff');
    [$parent] = createClassTeacherMember($school, SchoolRole::Parent, true, 'Active Parent');
    [$accountant] = createClassTeacherMember($school, SchoolRole::Accountant, true, 'School Accountant');
    [$student] = createClassTeacherMember($school, SchoolRole::Student);
    [$foreignTeacher] = createClassTeacherMember($otherSchool, SchoolRole::Teacher, true, 'Foreign Teacher');

    CurrentSchool::set($school);
    $openYear = AcademicYear::factory()->for($school)->current()->create(['name' => '2026-27']);
    $closedYear = AcademicYear::factory()->for($school)->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 4']);
    $sectionA = Section::factory()->for($school)->for($openYear)->for($grade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($openYear)->for($grade)->create(['name' => 'B']);
    $closedSection = Section::factory()->for($school)->for($closedYear)->for($grade)->create(['name' => 'Old']);
    $closedYear->closed_at = now();
    $closedYear->save();

    $action = new AssignClassTeacher;

    // Non-managers are denied.
    foreach ([$teacherA, $accountant, $parent, $student] as $unauthorized) {
        setPermissionsTeamId($school->id);
        expect(fn () => $action->handle($unauthorized, $sectionA, $teacherA))
            ->toThrow(AuthorizationException::class);
    }

    setPermissionsTeamId($school->id);

    // Parent, disabled staff, and foreign-school staff are refused cleanly.
    expect(fn () => $action->handle($admin, $sectionA, $parent))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($admin, $sectionA, $disabledTeacher))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($admin, $sectionA, $foreignTeacher))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($admin, $closedSection, $teacherA))->toThrow(ValidationException::class);

    // Owner assigns Teacher A to both section A and section B (doubling up allowed), plus Admin reassigns & unassigns.
    $beforeLogs = Activity::query()->count();

    $assigned = $action->handle($owner, $sectionA, $teacherA);
    expect($assigned->class_teacher_user_id)->toBe($teacherA->id);

    // Assigning same teacher again is a no-op with zero duplicate log.
    $action->handle($owner, $sectionA, $teacherA);
    expect(Activity::query()->count())->toBe($beforeLogs + 1);

    // Reassign A -> B, double-up on section B, and unassign section A.
    $action->handle($admin, $sectionB, $teacherA);
    expect($sectionB->fresh()->class_teacher_user_id)->toBe($teacherA->id);

    $reassigned = $action->handle($admin, $sectionA, $teacherB);
    expect($reassigned->class_teacher_user_id)->toBe($teacherB->id);

    $unassigned = $action->handle($owner, $sectionA, null);
    expect($unassigned->class_teacher_user_id)->toBeNull();

    $logs = Activity::query()->skip($beforeLogs)->take(10)->get();
    expect($logs)->toHaveCount(4);

    // Latest log reflects reassignment B -> null cleanly.
    $lastLog = $logs->last();
    expect($lastLog->event)->toBe('class_teacher_assigned')
        ->and($lastLog->properties->get('old_class_teacher_user_id'))->toBe($teacherB->id)
        ->and($lastLog->properties->get('new_class_teacher_user_id'))->toBeNull();
});

test('Sections screen displays Class teacher column, allows Owner/Admin assignment, flags disabled teachers, and blocks non-managers', function () {
    $school = School::factory()->active()->create();

    [$owner] = createClassTeacherMember($school, SchoolRole::Owner, true, 'School Owner');
    [$teacher, $teacherMembership] = createClassTeacherMember($school, SchoolRole::Teacher, true, 'Ananya Teacher');
    [$parent] = createClassTeacherMember($school, SchoolRole::Parent, true, 'Hidden Parent');

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->current()->create(['name' => '2026-27']);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 6']);
    $section = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);

    setPermissionsTeamId($school->id);

    // Owner sees assign select with active staff (Parent excluded) and can assign/unassign cleanly.
    $lw = Livewire::actingAs($owner)
        ->test(SectionsScreen::class)
        ->assertSee('Class teacher')
        ->assertSee('Ananya Teacher')
        ->assertDontSee('Hidden Parent')
        ->assertSee('data-test="assign-class-teacher-'.$section->id.'"', false)
        ->call('assignClassTeacher', $section->id, $teacher->id);

    expect($section->fresh()->class_teacher_user_id)->toBe($teacher->id);

    // Disabling the assigned teacher triggers the Disabled warning badge while keeping their name visible.
    $teacherMembership->disable();

    $lw->call('$refresh')
        ->assertSee('Ananya Teacher')
        ->assertSee('data-test="teacher-disabled-badge-'.$section->id.'"', false);

    // Teacher (view only on Sections, no attendance.manage) sees read-only name/badge without select and cannot call assignClassTeacher.
    $teacherMembership->activate();
    Livewire::actingAs($teacher)
        ->test(SectionsScreen::class)
        ->assertSee('Ananya Teacher')
        ->assertDontSee('data-test="assign-class-teacher-'.$section->id.'"', false)
        ->call('assignClassTeacher', $section->id, '')
        ->assertForbidden();

    // Owner can unassign cleanly back to null.
    Livewire::actingAs($owner)
        ->test(SectionsScreen::class)
        ->call('assignClassTeacher', $section->id, '');

    expect($section->fresh()->class_teacher_user_id)->toBeNull();
});
