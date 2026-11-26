<?php

use App\Enums\SchoolRole;
use App\Livewire\Setup\Sections;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Grade;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Create a user with an active membership and the given school role inside the school.
 */
function setupUserWithSectionRole(School $school, SchoolRole $role, ?User $existingUser = null): User
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
 * Enter a school's authorization context for section component testing.
 */
function actInSchoolForSections(User $user, School $school): void
{
    test()->actingAs($user);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
}

it('redirects guests to login when visiting the sections setup page', function () {
    $school = School::factory()->active()->create();

    $this->get(route('setup.sections', ['school' => $school->id]))
        ->assertRedirect(route('login'));
});

it('returns 404 when a non-member visits the sections setup page', function () {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->active()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('setup.sections', ['school' => $school->id]))
        ->assertNotFound();
});

it('forbids Parent and Student roles from opening the sections setup page', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSectionRole($school, $role);

    $this->actingAs($user)
        ->get(route('setup.sections', ['school' => $school->id]))
        ->assertForbidden();
})->with([
    'Parent' => [SchoolRole::Parent],
    'Student' => [SchoolRole::Student],
]);

it('allows Teacher and Accountant to view grouped sections without management controls', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSectionRole($school, $role);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->current()->create(['name' => '2026-27']);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 8]);
    $section = Section::factory()->for($school)->for($year)->for($grade)->create([
        'name' => 'Rose',
        'room' => 'Room 204',
        'capacity' => 38,
    ]);
    CurrentSchool::forget();

    $this->actingAs($user)
        ->get(route('setup.sections', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Class 5')
        ->assertSee('Rose')
        ->assertSee('Room 204')
        ->assertSee('38')
        ->assertDontSee('data-test="add-section-button"', false)
        ->assertDontSee('data-test="edit-section-'.$section->id.'"', false)
        ->assertDontSee('data-test="delete-section-'.$section->id.'"', false);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('forbids Teacher and Accountant from mutating sections through Livewire actions', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSectionRole($school, $role);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->current()->create();
    $grade = Grade::factory()->for($school)->create();
    $section = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);

    actInSchoolForSections($user, $school);

    Livewire::test(Sections::class)
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::test(Sections::class)
        ->call('openEditModal', $section->id)
        ->assertForbidden();

    Livewire::test(Sections::class)
        ->set('grade_id', $grade->id)
        ->set('name', 'B')
        ->call('save')
        ->assertForbidden();

    Livewire::test(Sections::class)
        ->call('confirmDelete', $section->id)
        ->assertForbidden();

    Livewire::test(Sections::class)
        ->call('delete', $section->id)
        ->assertForbidden();

    expect(Section::count())->toBe(1);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('allows Owner and Admin to view grouped sections in sort_order and create, edit, and delete with audit logs', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithSectionRole($school, $role);

    CurrentSchool::set($school);
    $currentYear = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $gradeOne = Grade::factory()->for($school)->create(['name' => 'Class 1', 'sort_order' => 1]);
    $gradeFive = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);
    $archivedGrade = Grade::factory()->for($school)->archived()->create(['name' => 'Old Grade', 'sort_order' => 9]);

    $this->actingAs($user)
        ->get(route('setup.sections', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="add-section-button"', false);

    actInSchoolForSections($user, $school);

    // Grade dropdown contains active grades only and omits archived grades
    $component = Livewire::test(Sections::class)
        ->assertSet('academic_year_id', $currentYear->id)
        ->assertSeeInOrder(['Class 1', 'Class 5'])
        ->assertDontSee('Old Grade');

    $component->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('grade_id', $gradeFive->id)
        ->set('name', 'A')
        ->set('capacity', 40)
        ->set('room', 'Room 101')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('grade_id', '')
        ->assertSet('name', '')
        ->assertSet('capacity', '')
        ->assertSet('room', '');

    $sectionFive = Section::where('grade_id', $gradeFive->id)->where('name', 'A')->first();
    expect($sectionFive)->not->toBeNull()
        ->and($sectionFive->capacity)->toBe(40)
        ->and($sectionFive->room)->toBe('Room 101');

    // Create section for lower sort_order grade and verify grouped order follows grade sort_order
    Livewire::test(Sections::class)
        ->call('openCreateModal')
        ->set('grade_id', $gradeOne->id)
        ->set('name', 'B')
        ->set('capacity', '')
        ->set('room', '')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(Sections::class)
        ->assertSeeInOrder(['Class 1', 'B', 'Class 5', 'A']);

    // Edit section
    Livewire::test(Sections::class)
        ->call('openEditModal', $sectionFive->id)
        ->assertSet('editingId', $sectionFive->id)
        ->set('room', 'Lab 2')
        ->set('capacity', 45)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($sectionFive->fresh()->room)->toBe('Lab 2')
        ->and($sectionFive->fresh()->capacity)->toBe(45);

    // Audit log check
    $updateAudit = Activity::where('log_name', 'section')
        ->where('subject_id', $sectionFive->id)
        ->where('event', 'updated')
        ->first();

    expect($updateAudit)->not->toBeNull()
        ->and($updateAudit->school_id)->toBe($school->id)
        ->and($updateAudit->causer_id)->toBe($user->id);

    // Delete via confirmation modal in open year
    Livewire::test(Sections::class)
        ->call('confirmDelete', $sectionFive->id)
        ->assertSet('deletingId', $sectionFive->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertHasNoErrors()
        ->assertSet('deletingId', null)
        ->assertSet('showDeleteModal', false);

    expect(Section::find($sectionFive->id))->toBeNull();
})->with([
    'Owner' => [SchoolRole::Owner],
    'Admin' => [SchoolRole::Admin],
]);

it('only offers the current schools academic years in the year selector', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $ownerA = setupUserWithSectionRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->current()->create(['name' => '2026-27']);
    $gradeA = Grade::factory()->for($schoolA)->create(['name' => 'Class 1']);
    Section::factory()->for($schoolA)->for($yearA)->for($gradeA)->create(['name' => 'A']);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->current()->create(['name' => '2099-00']);
    $gradeB = Grade::factory()->for($schoolB)->create(['name' => 'Foreign Grade']);
    Section::factory()->for($schoolB)->for($yearB)->for($gradeB)->create(['name' => 'ForeignSec']);

    actInSchoolForSections($ownerA, $schoolA);

    Livewire::test(Sections::class)
        ->assertSee('2026-27')
        ->assertDontSee('2099-00')
        ->set('academic_year_id', $yearB->id)
        ->assertSet('selectedAcademicYear', null)
        ->assertDontSee('ForeignSec')
        ->call('openCreateModal')
        ->assertSet('showFormModal', false)
        ->set('grade_id', $gradeA->id)
        ->set('name', 'B')
        ->call('save')
        ->assertHasErrors(['academic_year_id']);
});

it('shows a validation error rather than a database exception when submitting a duplicate section name on create or edit', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSectionRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->current()->create(['name' => '2026-27']);
    $gradeOne = Grade::factory()->for($school)->create(['name' => 'Class 1', 'sort_order' => 1]);
    $gradeTwo = Grade::factory()->for($school)->create(['name' => 'Class 2', 'sort_order' => 2]);

    Section::factory()->for($school)->for($year)->for($gradeOne)->create(['name' => 'A']);
    $otherSection = Section::factory()->for($school)->for($year)->for($gradeTwo)->create(['name' => 'A']);

    actInSchoolForSections($owner, $school);

    // Duplicate on create shows validation error
    Livewire::test(Sections::class)
        ->set('grade_id', $gradeOne->id)
        ->set('name', 'A')
        ->call('save')
        ->assertHasErrors(['name']);

    // Moving an existing section into another grade where that name already exists shows validation error, not 500
    Livewire::test(Sections::class)
        ->call('openEditModal', $otherSection->id)
        ->set('grade_id', $gradeOne->id)
        ->call('save')
        ->assertHasErrors(['name']);
});

it('keeps a closed academic year read-only on the sections screen', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSectionRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);

    // Create via un-closed year first, then close it
    $yearToClose = AcademicYear::factory()->for($school)->create([
        'name' => '2024-25',
        'starts_on' => '2024-04-01',
        'ends_on' => '2025-03-31',
    ]);
    $closedSection = Section::factory()->for($school)->for($yearToClose)->for($grade)->create([
        'name' => 'Rose',
        'room' => '101',
    ]);
    $yearToClose->closed_at = now();
    $yearToClose->save();

    actInSchoolForSections($owner, $school);

    Livewire::test(Sections::class)
        ->set('academic_year_id', $yearToClose->id)
        ->assertSee('data-test="closed-year-notice"', false)
        ->assertDontSee('data-test="add-section-button"', false)
        ->assertDontSee('data-test="edit-section-'.$closedSection->id.'"', false)
        ->assertDontSee('data-test="delete-section-'.$closedSection->id.'"', false)
        ->call('openCreateModal')
        ->assertSet('showFormModal', false)
        ->call('openEditModal', $closedSection->id)
        ->assertSet('showFormModal', false)
        ->set('grade_id', $grade->id)
        ->set('name', 'NewSec')
        ->call('save')
        ->assertHasErrors(['academic_year_id'])
        ->call('confirmDelete', $closedSection->id)
        ->assertSet('showDeleteModal', false)
        ->call('delete', $closedSection->id);

    expect($closedSection->fresh())->not->toBeNull()
        ->and(Section::where('academic_year_id', $yearToClose->id)->count())->toBe(1);
});

it('blocks IDOR attempts against another schools section in Livewire and over HTTP', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $ownerA = setupUserWithSectionRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolB);
    $sectionB = Section::factory()->recycle($schoolB)->create(['name' => 'Foreign']);

    actInSchoolForSections($ownerA, $schoolA);

    Livewire::test(Sections::class)
        ->call('openEditModal', $sectionB->id)
        ->assertNotFound();

    Livewire::test(Sections::class)
        ->call('confirmDelete', $sectionB->id)
        ->assertNotFound();

    Livewire::test(Sections::class)
        ->call('delete', $sectionB->id)
        ->assertNotFound();

    $pageResponse = $this->actingAs($ownerA)
        ->get(route('setup.sections', ['school' => $schoolA->id]))
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
                        ['path' => '', 'method' => 'delete', 'params' => [$sectionB->id]],
                    ],
                ],
            ],
        ])
        ->assertNotFound();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    CurrentSchool::set($schoolB);
    expect(Section::find($sectionB->id))->not->toBeNull();
});

it('locks editingId and deletingId properties against client-side tampering', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSectionRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $section = Section::factory()->recycle($school)->create(['name' => 'A']);

    actInSchoolForSections($owner, $school);

    expect(fn () => Livewire::test(Sections::class)->set('editingId', $section->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(Sections::class)->set('deletingId', $section->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('resets section form fields and validation errors on cancel and escapes section output', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithSectionRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->current()->create(['name' => '2026-27']);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 1', 'sort_order' => 1]);
    $section = Section::factory()->for($school)->for($year)->for($grade)->create([
        'name' => 'Rose',
        'room' => '<script>alert(1)</script>',
    ]);

    actInSchoolForSections($owner, $school);

    Livewire::test(Sections::class)
        ->call('openEditModal', $section->id)
        ->set('name', 'A-1')
        ->call('save')
        ->assertHasErrors(['name'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('grade_id', '')
        ->assertSet('name', '')
        ->assertSet('capacity', '')
        ->assertSet('room', '');

    CurrentSchool::forget();

    $content = $this->actingAs($owner)
        ->get(route('setup.sections', ['school' => $school->id]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('shows the Sections sidebar link according to per-school permissions', function () {
    $this->seed(PermissionSeeder::class);

    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $user = setupUserWithSectionRole($schoolA, SchoolRole::Teacher);
    setupUserWithSectionRole($schoolB, SchoolRole::Parent, $user);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('data-test="school-setup-sidebar-group"', false)
        ->assertSee(route('setup.sections', ['school' => $schoolA->id]), false);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $schoolB->id]))
        ->assertOk()
        ->assertDontSee('data-test="school-setup-sidebar-group"', false)
        ->assertDontSee(route('setup.sections', ['school' => $schoolB->id]), false);
});
