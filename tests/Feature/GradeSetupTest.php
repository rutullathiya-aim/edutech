<?php

use App\Enums\SchoolRole;
use App\Livewire\Setup\Grades;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Create a user with an active membership and the given school role inside the school.
 */
function setupUserWithGradeRole(School $school, SchoolRole $role, ?User $existingUser = null): User
{
    test()->seed(PermissionSeeder::class);

    $user = $existingUser ?? User::factory()->create();

    Membership::factory()->active()->for($user)->for($school)->create();

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return $user;
}

/**
 * Enter a school's authorization context for grade component testing.
 */
function actInSchoolForGrades(User $user, School $school): void
{
    test()->actingAs($user);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
}

it('redirects guests to login when visiting the grades setup page', function () {
    $school = School::factory()->active()->create();

    $this->get(route('setup.grades', ['school' => $school->id]))
        ->assertRedirect(route('login'));
});

it('returns 404 when a non-member visits the grades setup page', function () {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->active()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('setup.grades', ['school' => $school->id]))
        ->assertNotFound();
});

it('forbids Parent and Student roles from opening the grades setup page', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithGradeRole($school, $role);

    $this->actingAs($user)
        ->get(route('setup.grades', ['school' => $school->id]))
        ->assertForbidden();
})->with([
    'Parent' => [SchoolRole::Parent],
    'Student' => [SchoolRole::Student],
]);

it('allows Teacher and Accountant to view grades without management controls', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithGradeRole($school, $role);

    CurrentSchool::set($school);
    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 8,
    ]);
    CurrentSchool::forget();

    $this->actingAs($user)
        ->get(route('setup.grades', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Class 5')
        ->assertDontSee('data-test="add-standard-grades-button"', false)
        ->assertDontSee('data-test="add-grade-button"', false)
        ->assertDontSee('data-test="edit-grade-'.$grade->id.'"', false)
        ->assertDontSee('data-test="archive-grade-'.$grade->id.'"', false);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('forbids Teacher and Accountant from mutating grades through Livewire actions', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithGradeRole($school, $role);

    CurrentSchool::set($school);
    $activeGrade = Grade::factory()->for($school)->create([
        'name' => 'Class 1',
        'short_name' => '1',
        'sort_order' => 1,
    ]);
    $archivedGrade = Grade::factory()->for($school)->archived()->create([
        'name' => 'Old Grade',
        'short_name' => 'Old',
        'sort_order' => 2,
    ]);

    actInSchoolForGrades($user, $school);

    Livewire::test(Grades::class)
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::test(Grades::class)
        ->call('openEditModal', $activeGrade->id)
        ->assertForbidden();

    Livewire::test(Grades::class)
        ->set('name', 'Class 9')
        ->set('sort_order', 12)
        ->call('save')
        ->assertForbidden();

    Livewire::test(Grades::class)
        ->call('createStandardGrades')
        ->assertForbidden();

    Livewire::test(Grades::class)
        ->call('archive', $activeGrade->id)
        ->assertForbidden();

    Livewire::test(Grades::class)
        ->call('restore', $archivedGrade->id)
        ->assertForbidden();

    expect($activeGrade->fresh()->isArchived())->toBeFalse()
        ->and($archivedGrade->fresh()->isArchived())->toBeTrue()
        ->and(Grade::count())->toBe(2);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('allows Owner and Admin to add standard grades, create, edit, archive, and restore grades with audit logs', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithGradeRole($school, $role);

    $this->actingAs($user)
        ->get(route('setup.grades', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="add-standard-grades-button"', false)
        ->assertSee('data-test="add-grade-button"', false);

    actInSchoolForGrades($user, $school);

    // Add standard 15 grades idempotently
    Livewire::test(Grades::class)
        ->call('createStandardGrades')
        ->assertHasNoErrors();
    expect(Grade::count())->toBe(15);

    Livewire::test(Grades::class)
        ->call('createStandardGrades')
        ->assertHasNoErrors();
    expect(Grade::count())->toBe(15);

    // Create custom grade with blank short_name -> stored as null
    Livewire::test(Grades::class)
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('name', 'Playgroup')
        ->set('short_name', '')
        ->set('sort_order', 0)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('short_name', '')
        ->assertSet('sort_order', '');

    $customGrade = Grade::where('name', 'Playgroup')->first();
    expect($customGrade)->not->toBeNull()
        ->and($customGrade->short_name)->toBeNull()
        ->and($customGrade->sort_order)->toBe(0);

    // Edit custom grade
    Livewire::test(Grades::class)
        ->call('openEditModal', $customGrade->id)
        ->assertSet('editingId', $customGrade->id)
        ->set('name', 'Pre-Nursery')
        ->set('short_name', 'Pre')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($customGrade->fresh()->name)->toBe('Pre-Nursery')
        ->and($customGrade->fresh()->short_name)->toBe('Pre');

    // Archive and restore
    Livewire::test(Grades::class)
        ->call('archive', $customGrade->id)
        ->assertHasNoErrors();
    expect($customGrade->fresh()->isArchived())->toBeTrue();

    Livewire::test(Grades::class)
        ->call('restore', $customGrade->id)
        ->assertHasNoErrors();
    expect($customGrade->fresh()->isArchived())->toBeFalse();

    $archiveAudit = Activity::where('log_name', 'grade')
        ->where('subject_id', $customGrade->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($archiveAudit)->not->toBeNull()
        ->and($archiveAudit->school_id)->toBe($school->id)
        ->and($archiveAudit->causer_id)->toBe($user->id)
        ->and(array_keys($archiveAudit->attribute_changes?->toArray()['attributes'] ?? []))->toContain('archived_at');
})->with([
    'Owner' => [SchoolRole::Owner],
    'Admin' => [SchoolRole::Admin],
]);

it('blocks IDOR attempts against another schools grade in Livewire and over HTTP', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $ownerA = setupUserWithGradeRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolB);
    $gradeB = Grade::factory()->for($schoolB)->create([
        'name' => 'Class 1',
        'sort_order' => 1,
    ]);

    actInSchoolForGrades($ownerA, $schoolA);

    Livewire::test(Grades::class)
        ->call('openEditModal', $gradeB->id)
        ->assertNotFound();

    Livewire::test(Grades::class)
        ->call('archive', $gradeB->id)
        ->assertNotFound();

    Livewire::test(Grades::class)
        ->call('restore', $gradeB->id)
        ->assertNotFound();

    $pageResponse = $this->actingAs($ownerA)
        ->get(route('setup.grades', ['school' => $schoolA->id]))
        ->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $pageResponse->getContent(), $matches);
    expect($matches)->toHaveKey(1);

    $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

    $this->actingAs($ownerA)
        ->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => json_encode($snapshot),
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'archive', 'params' => [$gradeB->id]],
                    ],
                ],
            ],
        ])
        ->assertNotFound();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    CurrentSchool::set($schoolB);
    expect($gradeB->fresh()->isArchived())->toBeFalse();
});

it('locks the editingId property on grades against client-side tampering', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithGradeRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class 1',
        'sort_order' => 1,
    ]);

    actInSchoolForGrades($owner, $school);

    expect(fn () => Livewire::test(Grades::class)->set('editingId', $grade->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('validates grade name, short name, sort order, and uniqueness inside the Livewire form', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $owner = setupUserWithGradeRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolA);
    Grade::factory()->for($schoolA)->create([
        'name' => 'Class 1',
        'sort_order' => 1,
    ]);

    CurrentSchool::set($schoolB);
    Grade::factory()->for($schoolB)->create([
        'name' => 'Class 2',
        'sort_order' => 2,
    ]);

    actInSchoolForGrades($owner, $schoolA);

    Livewire::test(Grades::class)
        ->set('name', '')
        ->set('sort_order', '')
        ->call('save')
        ->assertHasErrors(['name', 'sort_order']);

    Livewire::test(Grades::class)
        ->set('name', 'Class 1')
        ->set('sort_order', 1)
        ->call('save')
        ->assertHasErrors(['name', 'sort_order']);

    Livewire::test(Grades::class)
        ->set('name', 'Class 10')
        ->set('short_name', 'TooLongShort')
        ->set('sort_order', 51)
        ->call('save')
        ->assertHasErrors(['short_name', 'sort_order']);

    // Name and sort_order used in School B are allowed in School A
    Livewire::test(Grades::class)
        ->set('name', 'Class 2')
        ->set('short_name', '2')
        ->set('sort_order', 2)
        ->call('save')
        ->assertHasNoErrors();
});

it('resets grade form fields and validation errors when cancelling the modal', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithGradeRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class 1',
        'short_name' => '1',
        'sort_order' => 1,
    ]);

    actInSchoolForGrades($owner, $school);

    Livewire::test(Grades::class)
        ->call('openEditModal', $grade->id)
        ->set('sort_order', 99)
        ->call('save')
        ->assertHasErrors(['sort_order'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('short_name', '')
        ->assertSet('sort_order', '');
});

it('escapes grade names rendered on the setup page', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithGradeRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    Grade::factory()->for($school)->create([
        'name' => '<script>alert(1)</script>',
        'sort_order' => 1,
    ]);
    CurrentSchool::forget();

    $content = $this->actingAs($owner)
        ->get(route('setup.grades', ['school' => $school->id]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});
