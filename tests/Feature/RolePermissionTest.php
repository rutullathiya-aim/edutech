<?php

use App\Enums\SchoolPermission;
use App\Enums\SchoolRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId(null);
});

test('roles seeder creates all six school roles globally with school_id null', function () {
    $this->seed(RoleSeeder::class);

    $roles = Role::whereNull('school_id')->get();

    expect($roles)->toHaveCount(6);

    foreach (SchoolRole::cases() as $roleEnum) {
        $found = $roles->firstWhere('name', $roleEnum->value);
        expect($found)->not->toBeNull()
            ->and($found->school_id)->toBeNull()
            ->and($found->guard_name)->toBe('web');
    }
});

test('a user is Teacher in School A and Parent in School B, and each school sees only its own role', function () {
    $this->seed(RoleSeeder::class);

    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $user = User::factory()->create();

    // Assign Teacher role in School A
    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Teacher);

    // Assign Parent role in School B
    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Parent);

    // Verify roles within School A
    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    expect($user->hasRole(SchoolRole::Teacher))->toBeTrue()
        ->and($user->hasRole(SchoolRole::Parent))->toBeFalse();

    // Verify roles within School B
    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    expect($user->hasRole(SchoolRole::Parent))->toBeTrue()
        ->and($user->hasRole(SchoolRole::Teacher))->toBeFalse();
});

test('removing a role in School A leaves School B untouched', function () {
    $this->seed(RoleSeeder::class);

    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $user = User::factory()->create();

    // Assign roles in School A and School B
    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Teacher);

    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Parent);

    // Remove role in School A
    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    $user->removeRole(SchoolRole::Teacher);
    $user->unsetRelation('roles');

    expect($user->hasRole(SchoolRole::Teacher))->toBeFalse();

    // Verify School B is unaffected
    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    expect($user->hasRole(SchoolRole::Parent))->toBeTrue();
});

test('running the roles seeder multiple times is idempotent and still gives exactly six roles', function () {
    $this->seed(RoleSeeder::class);
    expect(Role::count())->toBe(6);

    $this->seed(RoleSeeder::class);
    expect(Role::count())->toBe(6)
        ->and(Role::whereNull('school_id')->count())->toBe(6);
});

test('deleting a user removes their role assignments in every school', function () {
    $this->seed(RoleSeeder::class);

    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $user = User::factory()->create();

    // Assign roles in School A and School B
    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Teacher);

    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    $user->assignRole(SchoolRole::Parent);

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(2);

    $user->delete();

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(0);
});

test('permission seeder creates all setup permissions idempotently', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    expect(Role::count())->toBe(6)
        ->and(Permission::count())->toBe(10)
        ->and(Permission::pluck('name')->sort()->values()->all())->toBe([
            'academic_years.manage',
            'academic_years.view',
            'grades.manage',
            'grades.view',
            'sections.manage',
            'sections.view',
            'staff.manage',
            'staff.view',
            'subjects.manage',
            'subjects.view',
        ]);
});

test('permission seeder assigns the expected permissions to each school role', function (
    SchoolRole $role,
    array $allowedPermissions,
    array $deniedPermissions,
) {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->create();
    $user = User::factory()->create();

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');

    foreach ($allowedPermissions as $permission) {
        expect($user->hasPermissionTo($permission->value))->toBeTrue();
    }

    foreach ($deniedPermissions as $permission) {
        expect($user->hasPermissionTo($permission->value))->toBeFalse();
    }
})->with([
    'Owner' => [
        SchoolRole::Owner,
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesView,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsView,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsView,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
        [],
    ],
    'Admin' => [
        SchoolRole::Admin,
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesView,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsView,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsView,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
        [],
    ],
    'Accountant' => [
        SchoolRole::Accountant,
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::GradesView,
            SchoolPermission::SectionsView,
            SchoolPermission::SubjectsView,
        ],
        [
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
    ],
    'Teacher' => [
        SchoolRole::Teacher,
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::GradesView,
            SchoolPermission::SectionsView,
            SchoolPermission::SubjectsView,
        ],
        [
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
    ],
    'Parent' => [
        SchoolRole::Parent,
        [],
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesView,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsView,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsView,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
    ],
    'Student' => [
        SchoolRole::Student,
        [],
        [
            SchoolPermission::AcademicYearsView,
            SchoolPermission::AcademicYearsManage,
            SchoolPermission::GradesView,
            SchoolPermission::GradesManage,
            SchoolPermission::SectionsView,
            SchoolPermission::SectionsManage,
            SchoolPermission::SubjectsView,
            SchoolPermission::SubjectsManage,
            SchoolPermission::StaffView,
            SchoolPermission::StaffManage,
        ],
    ],
]);

test('policies enforce current school, matching team ID, permissions, and record school boundary', function () {
    $this->seed(PermissionSeeder::class);

    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $owner = User::factory()->create();
    $teacher = User::factory()->create();

    setPermissionsTeamId($schoolA->id);
    $owner->assignRole(SchoolRole::Owner->value);
    $teacher->assignRole(SchoolRole::Teacher->value);

    setPermissionsTeamId($schoolB->id);
    $owner->unsetRelation('roles');
    $owner->assignRole(SchoolRole::Parent->value);

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->create();
    $gradeA = Grade::factory()->for($schoolA)->create();
    $sectionA = Section::factory()->for($schoolA)->for($yearA)->for($gradeA)->create();
    $subjectA = Subject::factory()->for($schoolA)->create();

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create();
    $gradeB = Grade::factory()->for($schoolB)->create();
    $sectionB = Section::factory()->for($schoolB)->for($yearB)->for($gradeB)->create();
    $subjectB = Subject::factory()->for($schoolB)->create();

    // Fails closed without CurrentSchool or mismatched team ID
    CurrentSchool::forget();
    setPermissionsTeamId($schoolA->id);
    $owner->unsetRelation('roles', 'permissions');
    expect($owner->can('viewAny', AcademicYear::class))->toBeFalse()
        ->and($owner->can('viewAny', Grade::class))->toBeFalse()
        ->and($owner->can('viewAny', Section::class))->toBeFalse()
        ->and($owner->can('viewAny', Subject::class))->toBeFalse();

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolB->id);
    $owner->unsetRelation('roles', 'permissions');
    expect($owner->can('viewAny', AcademicYear::class))->toBeFalse()
        ->and($owner->can('create', Grade::class))->toBeFalse()
        ->and($owner->can('create', Section::class))->toBeFalse()
        ->and($owner->can('create', Subject::class))->toBeFalse();

    // Valid School A context
    setPermissionsTeamId($schoolA->id);
    $owner->unsetRelation('roles', 'permissions');
    $teacher->unsetRelation('roles', 'permissions');

    expect($owner->can('viewAny', AcademicYear::class))->toBeTrue()
        ->and($owner->can('create', AcademicYear::class))->toBeTrue()
        ->and($owner->can('update', $yearA))->toBeTrue()
        ->and($owner->can('makeCurrent', $yearA))->toBeTrue()
        ->and($owner->can('update', $yearB))->toBeFalse()
        ->and($owner->can('viewAny', Grade::class))->toBeTrue()
        ->and($owner->can('create', Grade::class))->toBeTrue()
        ->and($owner->can('archive', $gradeA))->toBeTrue()
        ->and($owner->can('restore', $gradeA))->toBeTrue()
        ->and($owner->can('delete', $gradeA))->toBeFalse()
        ->and($owner->can('update', $gradeB))->toBeFalse()
        ->and($owner->can('viewAny', Section::class))->toBeTrue()
        ->and($owner->can('create', Section::class))->toBeTrue()
        ->and($owner->can('update', $sectionA))->toBeTrue()
        ->and($owner->can('delete', $sectionA))->toBeTrue()
        ->and($owner->can('update', $sectionB))->toBeFalse()
        ->and($owner->can('delete', $sectionB))->toBeFalse()
        ->and($owner->can('viewAny', Subject::class))->toBeTrue()
        ->and($owner->can('create', Subject::class))->toBeTrue()
        ->and($owner->can('update', $subjectA))->toBeTrue()
        ->and($owner->can('archive', $subjectA))->toBeTrue()
        ->and($owner->can('restore', $subjectA))->toBeTrue()
        ->and($owner->can('manageCurriculum', Subject::class))->toBeTrue()
        ->and($owner->can('delete', $subjectA))->toBeFalse()
        ->and($owner->can('update', $subjectB))->toBeFalse()
        ->and($teacher->can('viewAny', AcademicYear::class))->toBeTrue()
        ->and($teacher->can('create', AcademicYear::class))->toBeFalse()
        ->and($teacher->can('update', $yearA))->toBeFalse()
        ->and($teacher->can('viewAny', Grade::class))->toBeTrue()
        ->and($teacher->can('create', Grade::class))->toBeFalse()
        ->and($teacher->can('archive', $gradeA))->toBeFalse()
        ->and($teacher->can('viewAny', Section::class))->toBeTrue()
        ->and($teacher->can('create', Section::class))->toBeFalse()
        ->and($teacher->can('update', $sectionA))->toBeFalse()
        ->and($teacher->can('delete', $sectionA))->toBeFalse()
        ->and($teacher->can('viewAny', Subject::class))->toBeTrue()
        ->and($teacher->can('create', Subject::class))->toBeFalse()
        ->and($teacher->can('archive', $subjectA))->toBeFalse()
        ->and($teacher->can('manageCurriculum', Subject::class))->toBeFalse();
});

test('SchoolRole hierarchy helpers define staff roles, ranks, and assignable permissions cleanly', function () {
    expect(SchoolRole::staffCases())->toBe([
        SchoolRole::Owner,
        SchoolRole::Admin,
        SchoolRole::Accountant,
        SchoolRole::Teacher,
    ]);

    expect(SchoolRole::Owner->isStaff())->toBeTrue()
        ->and(SchoolRole::Admin->isStaff())->toBeTrue()
        ->and(SchoolRole::Accountant->isStaff())->toBeTrue()
        ->and(SchoolRole::Teacher->isStaff())->toBeTrue()
        ->and(SchoolRole::Parent->isStaff())->toBeFalse()
        ->and(SchoolRole::Student->isStaff())->toBeFalse();

    expect(SchoolRole::Owner->rank())->toBeGreaterThan(SchoolRole::Admin->rank())
        ->and(SchoolRole::Admin->rank())->toBeGreaterThan(SchoolRole::Accountant->rank())
        ->and(SchoolRole::Accountant->rank())->toBeGreaterThan(SchoolRole::Teacher->rank())
        ->and(SchoolRole::Teacher->rank())->toBeGreaterThan(SchoolRole::Parent->rank())
        ->and(SchoolRole::Parent->rank())->toBeGreaterThan(SchoolRole::Student->rank());

    expect(SchoolRole::assignableBy(SchoolRole::Owner))->toBe([
        SchoolRole::Admin,
        SchoolRole::Accountant,
        SchoolRole::Teacher,
    ])->and(SchoolRole::assignableBy(SchoolRole::Admin))->toBe([
        SchoolRole::Accountant,
        SchoolRole::Teacher,
    ])->and(SchoolRole::assignableBy(SchoolRole::Accountant))->toBe([])
        ->and(SchoolRole::assignableBy(SchoolRole::Teacher))->toBe([])
        ->and(SchoolRole::assignableBy(SchoolRole::Parent))->toBe([])
        ->and(SchoolRole::assignableBy(SchoolRole::Student))->toBe([]);

    expect(SchoolRole::Owner->canAssign(SchoolRole::Owner))->toBeFalse()
        ->and(SchoolRole::Owner->canManage(SchoolRole::Owner))->toBeFalse()
        ->and(SchoolRole::Owner->canAssign(SchoolRole::Admin))->toBeTrue()
        ->and(SchoolRole::Admin->canManage(SchoolRole::Admin))->toBeFalse()
        ->and(SchoolRole::Admin->canManage(SchoolRole::Owner))->toBeFalse()
        ->and(SchoolRole::Admin->canManage(SchoolRole::Accountant))->toBeTrue()
        ->and(SchoolRole::Admin->canManage(SchoolRole::Teacher))->toBeTrue();
});

test('MembershipPolicy and InvitationPolicy enforce hierarchy, self-protection, and school boundaries', function () {
    $this->seed(PermissionSeeder::class);

    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $owner = User::factory()->create();
    $adminA = User::factory()->create();
    $otherAdminA = User::factory()->create();
    $teacherA = User::factory()->create();
    $parentA = User::factory()->create();
    $teacherB = User::factory()->create();

    $ownerMembership = Membership::factory()->for($schoolA)->for($owner)->create();
    $adminMembership = Membership::factory()->for($schoolA)->for($adminA)->create();
    $otherAdminMembership = Membership::factory()->for($schoolA)->for($otherAdminA)->create();
    $teacherMembership = Membership::factory()->for($schoolA)->for($teacherA)->create();
    $parentMembership = Membership::factory()->for($schoolA)->for($parentA)->create();
    $foreignMembership = Membership::factory()->for($schoolB)->for($teacherB)->create();

    setPermissionsTeamId($schoolA->id);
    $owner->assignRole(SchoolRole::Owner->value);
    $adminA->assignRole(SchoolRole::Admin->value);
    $otherAdminA->assignRole(SchoolRole::Admin->value);
    $teacherA->assignRole(SchoolRole::Teacher->value);
    $parentA->assignRole(SchoolRole::Parent->value);

    setPermissionsTeamId($schoolB->id);
    $teacherB->assignRole(SchoolRole::Teacher->value);

    CurrentSchool::set($schoolB);
    $foreignInvitation = Invitation::factory()->for($schoolB)->pending()->create(['role' => SchoolRole::Teacher]);

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolA->id);
    $owner->unsetRelation('roles', 'permissions');
    $adminA->unsetRelation('roles', 'permissions');
    $teacherA->unsetRelation('roles', 'permissions');

    $adminInvitation = Invitation::factory()->for($schoolA)->pending()->create(['role' => SchoolRole::Admin]);
    $teacherInvitation = Invitation::factory()->for($schoolA)->pending()->create(['role' => SchoolRole::Teacher]);
    $acceptedInvitation = Invitation::factory()->for($schoolA)->accepted()->create(['role' => SchoolRole::Teacher]);

    // Owner hierarchy + self check
    expect($owner->can('viewAny', Membership::class))->toBeTrue()
        ->and($owner->can('create', Membership::class))->toBeTrue()
        ->and($owner->can('update', $ownerMembership))->toBeFalse()
        ->and($owner->can('update', $adminMembership))->toBeTrue()
        ->and($owner->can('update', $teacherMembership))->toBeTrue()
        ->and($owner->can('update', $parentMembership))->toBeFalse()
        ->and($owner->can('update', $foreignMembership))->toBeFalse()
        ->and($owner->can('delete', $teacherMembership))->toBeFalse()
        ->and($owner->can('resend', $adminInvitation))->toBeTrue()
        ->and($owner->can('revoke', $adminInvitation))->toBeTrue()
        ->and($owner->can('resend', $acceptedInvitation))->toBeFalse()
        ->and($owner->can('revoke', $acceptedInvitation))->toBeFalse()
        ->and($owner->can('resend', $foreignInvitation))->toBeFalse();

    // Admin hierarchy + self check
    expect($adminA->can('viewAny', Membership::class))->toBeTrue()
        ->and($adminA->can('create', Membership::class))->toBeTrue()
        ->and($adminA->can('update', $ownerMembership))->toBeFalse()
        ->and($adminA->can('update', $adminMembership))->toBeFalse()
        ->and($adminA->can('update', $otherAdminMembership))->toBeFalse()
        ->and($adminA->can('update', $teacherMembership))->toBeTrue()
        ->and($adminA->can('resend', $adminInvitation))->toBeFalse()
        ->and($adminA->can('revoke', $adminInvitation))->toBeFalse()
        ->and($adminA->can('resend', $teacherInvitation))->toBeTrue()
        ->and($adminA->can('revoke', $teacherInvitation))->toBeTrue();

    // Teacher has no staff view or management rights
    expect($teacherA->can('viewAny', Membership::class))->toBeFalse()
        ->and($teacherA->can('view', $teacherMembership))->toBeFalse()
        ->and($teacherA->can('create', Membership::class))->toBeFalse()
        ->and($teacherA->can('update', $teacherMembership))->toBeFalse()
        ->and($teacherA->can('viewAny', Invitation::class))->toBeFalse()
        ->and($teacherA->can('resend', $teacherInvitation))->toBeFalse();
});
