<?php

use App\Enums\SchoolRole;
use App\Enums\SubjectKind;
use App\Livewire\Setup\Subjects;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\Membership;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Create a user with an active membership and the given school role inside the school.
 */
function setupUserWithSubjectRole(School $school, SchoolRole $role, ?User $existingUser = null): User
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
 * Enter a school's authorization context for subject component testing.
 */
function actInSchoolForSubjects(User $user, School $school): void
{
    test()->actingAs($user);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
}

it('redirects guests to login when visiting the subjects setup page', function () {
    $school = School::factory()->active()->create();

    $this->get(route('setup.subjects', ['school' => $school->id]))
        ->assertRedirect(route('login'));
});

it('returns 404 when a non-member visits the subjects setup page', function () {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->active()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('setup.subjects', ['school' => $school->id]))
        ->assertNotFound();
});

it('forbids Parent and Student roles from opening the subjects setup page', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSubjectRole($school, $role);

    $this->actingAs($user)
        ->get(route('setup.subjects', ['school' => $school->id]))
        ->assertForbidden();
})->with([
    'Parent' => [SchoolRole::Parent],
    'Student' => [SchoolRole::Student],
]);

it('allows Teacher and Accountant to view subjects and curriculum without management controls', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSubjectRole($school, $role);

    CurrentSchool::set($school);
    $subject = Subject::factory()->for($school)->create([
        'name' => 'Mathematics',
        'code' => '041',
        'kind' => SubjectKind::Scholastic,
    ]);
    CurrentSchool::forget();

    $this->actingAs($user)
        ->get(route('setup.subjects', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Mathematics')
        ->assertSee('041')
        ->assertDontSee('data-test="add-standard-subjects-button"', false)
        ->assertDontSee('data-test="add-subject-button"', false)
        ->assertDontSee('data-test="edit-subject-'.$subject->id.'"', false)
        ->assertDontSee('data-test="archive-subject-'.$subject->id.'"', false);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('forbids Teacher and Accountant from mutating subjects or curriculum through Livewire actions', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSubjectRole($school, $role);

    CurrentSchool::set($school);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);
    $activeSubject = Subject::factory()->for($school)->create(['name' => 'English']);
    $archivedSubject = Subject::factory()->for($school)->archived()->create(['name' => 'Old Typing']);

    actInSchoolForSubjects($user, $school);

    Livewire::test(Subjects::class)
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->call('openEditModal', $activeSubject->id)
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->set('name', 'Chemistry')
        ->set('code', '043')
        ->set('kind', SubjectKind::Scholastic->value)
        ->call('save')
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->call('createStandardSubjects')
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->call('archive', $activeSubject->id)
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->call('restore', $archivedSubject->id)
        ->assertForbidden();

    Livewire::test(Subjects::class)
        ->call('setTab', 'curriculum')
        ->set('selectedGradeId', $grade->id)
        ->set("curriculumRows.{$activeSubject->id}.enabled", true)
        ->set("curriculumRows.{$activeSubject->id}.sort_order", 1)
        ->call('saveCurriculum')
        ->assertForbidden();

    expect($activeSubject->fresh()->isArchived())->toBeFalse()
        ->and($archivedSubject->fresh()->isArchived())->toBeTrue()
        ->and(GradeSubject::count())->toBe(0);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('allows Owner and Admin to manage subjects, standard subjects, archive/restore, and grade curriculum with audit logs', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSubjectRole($school, $role);

    CurrentSchool::set($school);
    $gradeFive = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);
    $gradeSix = Grade::factory()->for($school)->create(['name' => 'Class 6', 'sort_order' => 6]);
    $archivedGrade = Grade::factory()->for($school)->archived()->create(['name' => 'Old Grade', 'sort_order' => 9]);

    $this->actingAs($user)
        ->get(route('setup.subjects', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="add-standard-subjects-button"', false)
        ->assertSee('data-test="add-subject-button"', false);

    actInSchoolForSubjects($user, $school);

    Livewire::test(Subjects::class)
        ->call('createStandardSubjects')
        ->assertHasNoErrors();
    expect(Subject::count())->toBe(17);

    Livewire::test(Subjects::class)
        ->call('createStandardSubjects')
        ->assertHasNoErrors();
    expect(Subject::count())->toBe(17);

    Livewire::test(Subjects::class)
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('name', 'Robotics Lab')
        ->set('code', 'ROB-101')
        ->set('kind', SubjectKind::CoScholastic->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('code', '');

    $custom = Subject::where('name', 'Robotics Lab')->first();
    expect($custom)->not->toBeNull()
        ->and($custom->code)->toBe('ROB-101')
        ->and($custom->kind)->toBe(SubjectKind::CoScholastic);

    Livewire::test(Subjects::class)
        ->call('openEditModal', $custom->id)
        ->assertSet('editingId', $custom->id)
        ->set('name', 'Applied Robotics')
        ->set('code', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($custom->fresh()->name)->toBe('Applied Robotics')
        ->and($custom->fresh()->code)->toBeNull();

    // Archive and restore
    Livewire::test(Subjects::class)
        ->call('archive', $custom->id)
        ->assertHasNoErrors();
    expect($custom->fresh()->isArchived())->toBeTrue();

    Livewire::test(Subjects::class)
        ->call('restore', $custom->id)
        ->assertHasNoErrors();
    expect($custom->fresh()->isArchived())->toBeFalse();

    $archiveAudit = Activity::where('log_name', 'subject')
        ->where('subject_id', $custom->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($archiveAudit)->not->toBeNull()
        ->and($archiveAudit->school_id)->toBe($school->id)
        ->and($archiveAudit->causer_id)->toBe($user->id);

    // Switch to curriculum tab: active grades only, save curriculum via SyncGradeSubjects
    $english = Subject::where('name', 'English')->firstOrFail();

    Livewire::test(Subjects::class)
        ->call('setTab', 'curriculum')
        ->assertSee($gradeFive->name)
        ->assertSee($gradeSix->name)
        ->assertDontSee($archivedGrade->name)
        ->set('selectedGradeId', $gradeFive->id)
        ->set("curriculumRows.{$english->id}.enabled", true)
        ->set("curriculumRows.{$english->id}.is_optional", false)
        ->set("curriculumRows.{$english->id}.sort_order", 1)
        ->set("curriculumRows.{$custom->id}.enabled", true)
        ->set("curriculumRows.{$custom->id}.is_optional", true)
        ->set("curriculumRows.{$custom->id}.sort_order", 2)
        ->call('saveCurriculum')
        ->assertHasNoErrors();

    $saved = GradeSubject::where('grade_id', $gradeFive->id)->orderBy('sort_order')->get();
    expect($saved)->toHaveCount(2)
        ->and($saved[0]->subject_id)->toBe($english->id)
        ->and($saved[0]->is_optional)->toBeFalse()
        ->and($saved[1]->subject_id)->toBe($custom->id)
        ->and($saved[1]->is_optional)->toBeTrue();
})->with([
    'Owner' => [SchoolRole::Owner],
    'Admin' => [SchoolRole::Admin],
]);

it('blocks IDOR attempts against another schools subjects and curriculum in Livewire and over HTTP', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $ownerA = setupUserWithSubjectRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolB);
    $gradeB = Grade::factory()->for($schoolB)->create();
    $subjectB = Subject::factory()->for($schoolB)->create(['name' => 'Foreign Subject']);

    actInSchoolForSubjects($ownerA, $schoolA);

    Livewire::test(Subjects::class)
        ->call('openEditModal', $subjectB->id)
        ->assertNotFound();

    Livewire::test(Subjects::class)
        ->call('archive', $subjectB->id)
        ->assertNotFound();

    Livewire::test(Subjects::class)
        ->call('restore', $subjectB->id)
        ->assertNotFound();

    // Tampered foreign grade or foreign subject in curriculum fails validation without altering School B
    Livewire::test(Subjects::class)
        ->call('setTab', 'curriculum')
        ->set('selectedGradeId', $gradeB->id)
        ->set("curriculumRows.{$subjectB->id}", [
            'enabled' => true,
            'is_optional' => false,
            'sort_order' => 1,
        ])
        ->call('saveCurriculum')
        ->assertHasErrors(['grade_id', 'items.0.subject_id']);

    $pageResponse = $this->actingAs($ownerA)
        ->get(route('setup.subjects', ['school' => $schoolA->id]))
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
                        ['path' => '', 'method' => 'archive', 'params' => [$subjectB->id]],
                    ],
                ],
            ],
        ])
        ->assertNotFound();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    CurrentSchool::set($schoolB);
    expect($subjectB->fresh()->isArchived())->toBeFalse()
        ->and(GradeSubject::count())->toBe(0);
});

it('locks editingId against client-side tampering on Subjects', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSubjectRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $subject = Subject::factory()->for($school)->create();

    actInSchoolForSubjects($owner, $school);

    expect(fn () => Livewire::test(Subjects::class)->set('editingId', $subject->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('validates duplicate subject names/codes, resets form state on cancel, and escapes output', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSubjectRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $existing = Subject::factory()->for($school)->create([
        'name' => '<script>alert(1)</script>',
        'code' => '041',
    ]);

    actInSchoolForSubjects($owner, $school);

    Livewire::test(Subjects::class)
        ->set('name', '<script>alert(1)</script>')
        ->set('code', '041')
        ->set('kind', SubjectKind::Scholastic->value)
        ->call('save')
        ->assertHasErrors(['name', 'code']);

    Livewire::test(Subjects::class)
        ->call('openEditModal', $existing->id)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('code', '');

    CurrentSchool::forget();

    $content = $this->actingAs($owner)
        ->get(route('setup.subjects', ['school' => $school->id]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('shows the Subjects sidebar link according to per-school permissions', function () {
    $this->seed(PermissionSeeder::class);

    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $user = setupUserWithSubjectRole($schoolA, SchoolRole::Teacher);
    setupUserWithSubjectRole($schoolB, SchoolRole::Parent, $user);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('data-test="school-setup-sidebar-group"', false)
        ->assertSee(route('setup.subjects', ['school' => $schoolA->id]), false);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $schoolB->id]))
        ->assertOk()
        ->assertDontSee('data-test="school-setup-sidebar-group"', false)
        ->assertDontSee(route('setup.subjects', ['school' => $schoolB->id]), false);
});
