<?php

use App\Enums\SchoolRole;
use App\Livewire\Setup\AcademicYears;
use App\Models\AcademicYear;
use App\Models\Activity;
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
function setupUserWithAcademicYearRole(School $school, SchoolRole $role, ?User $existingUser = null): User
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
 * Enter a school's authorization context for component testing.
 */
function actInSchoolForAcademicYears(User $user, School $school): void
{
    test()->actingAs($user);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
}

it('redirects guests to login when visiting the academic years setup page', function () {
    $school = School::factory()->active()->create();

    $this->get(route('setup.academic-years', ['school' => $school->id]))
        ->assertRedirect(route('login'));
});

it('returns 404 when a non-member visits the academic years setup page', function () {
    $this->seed(PermissionSeeder::class);

    $school = School::factory()->active()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('setup.academic-years', ['school' => $school->id]))
        ->assertNotFound();
});

it('forbids Parent and Student roles from opening the academic years setup page', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithAcademicYearRole($school, $role);

    $this->actingAs($user)
        ->get(route('setup.academic-years', ['school' => $school->id]))
        ->assertForbidden();
})->with([
    'Parent' => [SchoolRole::Parent],
    'Student' => [SchoolRole::Student],
]);

it('allows Teacher and Accountant to view academic years without management controls', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithAcademicYearRole($school, $role);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    CurrentSchool::forget();

    $this->actingAs($user)
        ->get(route('setup.academic-years', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('2026-27')
        ->assertDontSee('data-test="add-academic-year-button"', false)
        ->assertDontSee('data-test="make-current-'.$year->id.'"', false)
        ->assertDontSee('data-test="edit-academic-year-'.$year->id.'"', false);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('forbids Teacher and Accountant from mutating academic years through Livewire actions', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithAcademicYearRole($school, $role);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    actInSchoolForAcademicYears($user, $school);

    Livewire::test(AcademicYears::class)
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::test(AcademicYears::class)
        ->call('openEditModal', $year->id)
        ->assertForbidden();

    Livewire::test(AcademicYears::class)
        ->set('name', '2027-28')
        ->set('starts_on', '2027-04-01')
        ->set('ends_on', '2028-03-31')
        ->call('save')
        ->assertForbidden();

    Livewire::test(AcademicYears::class)
        ->call('makeCurrent', $year->id)
        ->assertForbidden();

    expect($year->fresh()->is_current)->toBeFalse()
        ->and(AcademicYear::count())->toBe(1);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
]);

it('allows Owner and Admin to view, create, edit, and mark an academic year as current with audit logs', function (SchoolRole $role) {
    $school = School::factory()->active()->create();
    $user = setupUserWithAcademicYearRole($school, $role);

    CurrentSchool::set($school);
    $oldYear = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);

    $this->actingAs($user)
        ->get(route('setup.academic-years', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('data-test="add-academic-year-button"', false);

    actInSchoolForAcademicYears($user, $school);

    Livewire::test(AcademicYears::class)
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('name', '2026-27')
        ->set('starts_on', '2026-04-01')
        ->set('ends_on', '2027-03-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('starts_on', '')
        ->assertSet('ends_on', '');

    $created = AcademicYear::where('name', '2026-27')->first();
    expect($created)->not->toBeNull();

    Livewire::test(AcademicYears::class)
        ->call('openEditModal', $created->id)
        ->assertSet('editingId', $created->id)
        ->assertSet('showFormModal', true)
        ->set('ends_on', '2027-03-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null);

    expect($created->fresh()->ends_on->toDateString())->toBe('2027-03-31');

    Livewire::test(AcademicYears::class)
        ->call('makeCurrent', $created->id)
        ->assertHasNoErrors();

    expect($oldYear->fresh()->is_current)->toBeFalse()
        ->and($created->fresh()->is_current)->toBeTrue();

    $createAudit = Activity::where('log_name', 'academic_year')
        ->where('subject_id', $created->id)
        ->where('event', 'created')
        ->first();
    $updateAudit = Activity::where('log_name', 'academic_year')
        ->where('subject_id', $created->id)
        ->where('event', 'updated')
        ->first();
    $currentAudit = Activity::where('log_name', 'academic_year')
        ->where('subject_id', $created->id)
        ->where('event', 'academic_year_marked_current')
        ->first();

    expect($createAudit)->not->toBeNull()
        ->and($createAudit->school_id)->toBe($school->id)
        ->and($createAudit->causer_id)->toBe($user->id)
        ->and($updateAudit)->not->toBeNull()
        ->and($updateAudit->school_id)->toBe($school->id)
        ->and($currentAudit)->not->toBeNull()
        ->and($currentAudit->school_id)->toBe($school->id)
        ->and($currentAudit->causer_id)->toBe($user->id);
})->with([
    'Owner' => [SchoolRole::Owner],
    'Admin' => [SchoolRole::Admin],
]);

it('blocks IDOR attempts against another schools academic year in Livewire and over HTTP', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $ownerA = setupUserWithAcademicYearRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);

    actInSchoolForAcademicYears($ownerA, $schoolA);

    Livewire::test(AcademicYears::class)
        ->call('openEditModal', $yearB->id)
        ->assertNotFound();

    Livewire::test(AcademicYears::class)
        ->call('makeCurrent', $yearB->id)
        ->assertNotFound();

    $pageResponse = $this->actingAs($ownerA)
        ->get(route('setup.academic-years', ['school' => $schoolA->id]))
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
                        ['path' => '', 'method' => 'makeCurrent', 'params' => [$yearB->id]],
                    ],
                ],
            ],
        ])
        ->assertNotFound();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    CurrentSchool::set($schoolB);
    expect($yearB->fresh()->is_current)->toBeFalse();
});

it('locks the editingId property against client-side tampering', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithAcademicYearRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    actInSchoolForAcademicYears($owner, $school);

    expect(fn () => Livewire::test(AcademicYears::class)->set('editingId', $year->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('validates academic year format, duplicates per school, overlaps, and duration inside the Livewire form', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();
    $owner = setupUserWithAcademicYearRole($schoolA, SchoolRole::Owner);

    CurrentSchool::set($schoolA);
    AcademicYear::factory()->for($schoolA)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    CurrentSchool::set($schoolB);
    AcademicYear::factory()->for($schoolB)->create([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);

    actInSchoolForAcademicYears($owner, $schoolA);

    Livewire::test(AcademicYears::class)
        ->set('name', 'bad-format')
        ->set('starts_on', '2027-04-01')
        ->set('ends_on', '2028-03-31')
        ->call('save')
        ->assertHasErrors(['name']);

    Livewire::test(AcademicYears::class)
        ->set('name', '2026-27')
        ->set('starts_on', '2027-04-01')
        ->set('ends_on', '2028-03-31')
        ->call('save')
        ->assertHasErrors(['name']);

    Livewire::test(AcademicYears::class)
        ->set('name', '2027-28')
        ->set('starts_on', '2027-03-15')
        ->set('ends_on', '2028-03-31')
        ->call('save')
        ->assertHasErrors(['ends_on']);

    // Same name existing in School B succeeds in School A when dates do not overlap
    Livewire::test(AcademicYears::class)
        ->set('name', '2027-28')
        ->set('starts_on', '2027-04-01')
        ->set('ends_on', '2028-03-31')
        ->call('save')
        ->assertHasNoErrors();
});

it('resets form fields and validation errors when cancelling the modal', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithAcademicYearRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $year = AcademicYear::factory()->for($school)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    actInSchoolForAcademicYears($owner, $school);

    Livewire::test(AcademicYears::class)
        ->call('openEditModal', $year->id)
        ->set('name', 'invalid')
        ->call('save')
        ->assertHasErrors(['name'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '')
        ->assertSet('starts_on', '')
        ->assertSet('ends_on', '');
});

it('refuses editing or making current a closed academic year', function () {
    $school = School::factory()->active()->create();
    $owner = setupUserWithAcademicYearRole($school, SchoolRole::Owner);

    CurrentSchool::set($school);
    $closedYear = AcademicYear::factory()->for($school)->closed()->create([
        'name' => '2024-25',
        'starts_on' => '2024-04-01',
        'ends_on' => '2025-03-31',
    ]);

    actInSchoolForAcademicYears($owner, $school);

    Livewire::test(AcademicYears::class)
        ->call('openEditModal', $closedYear->id)
        ->assertSet('showFormModal', false)
        ->assertSet('editingId', null)
        ->call('makeCurrent', $closedYear->id);

    expect($closedYear->fresh()->is_current)->toBeFalse();
});

it('shows the School setup sidebar group based on per-school permissions and hides it on non-school pages', function () {
    $this->seed(PermissionSeeder::class);

    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $multiRoleUser = User::factory()->create();
    Membership::factory()->active()->for($multiRoleUser)->for($schoolA)->create();
    Membership::factory()->active()->for($multiRoleUser)->for($schoolB)->create();

    setPermissionsTeamId($schoolA->id);
    $multiRoleUser->assignRole(SchoolRole::Owner->value);

    setPermissionsTeamId($schoolB->id);
    $multiRoleUser->unsetRelation('roles');
    $multiRoleUser->assignRole(SchoolRole::Parent->value);
    setPermissionsTeamId(null);

    $teacher = setupUserWithAcademicYearRole($schoolA, SchoolRole::Teacher);

    $this->actingAs($multiRoleUser)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('data-test="school-setup-sidebar-group"', false)
        ->assertSee(route('setup.academic-years', ['school' => $schoolA->id]), false)
        ->assertSee(route('setup.grades', ['school' => $schoolA->id]), false);

    $this->actingAs($teacher)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('data-test="school-setup-sidebar-group"', false);

    $this->actingAs($multiRoleUser)
        ->get(route('dashboard', ['school' => $schoolB->id]))
        ->assertOk()
        ->assertDontSee('data-test="school-setup-sidebar-group"', false)
        ->assertDontSee(route('setup.academic-years', ['school' => $schoolB->id]), false)
        ->assertDontSee(route('setup.grades', ['school' => $schoolB->id]), false);

    $this->actingAs($multiRoleUser)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('data-test="school-setup-sidebar-group"', false);
});

it('restores the matching school context during real Livewire HTTP update requests across schools', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $user = setupUserWithAcademicYearRole($schoolA, SchoolRole::Owner);
    setupUserWithAcademicYearRole($schoolB, SchoolRole::Owner, $user);

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    CurrentSchool::forget();

    // Act on School A via real HTTP Livewire endpoint
    $responseA = $this->actingAs($user)
        ->get(route('setup.academic-years', ['school' => $schoolA->id]))
        ->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $responseA->getContent(), $matchesA);
    $snapshotA = json_decode(html_entity_decode($matchesA[1], ENT_QUOTES | ENT_HTML5), true);

    $this->actingAs($user)
        ->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => json_encode($snapshotA),
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'makeCurrent', 'params' => [$yearA->id]],
                    ],
                ],
            ],
        ])
        ->assertOk();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    // Act on School B via real HTTP Livewire endpoint
    $responseB = $this->actingAs($user)
        ->get(route('setup.academic-years', ['school' => $schoolB->id]))
        ->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $responseB->getContent(), $matchesB);
    $snapshotB = json_decode(html_entity_decode($matchesB[1], ENT_QUOTES | ENT_HTML5), true);

    $this->actingAs($user)
        ->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => json_encode($snapshotB),
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'makeCurrent', 'params' => [$yearB->id]],
                    ],
                ],
            ],
        ])
        ->assertOk();

    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    CurrentSchool::set($schoolA);
    expect($yearA->fresh()->is_current)->toBeTrue();

    CurrentSchool::set($schoolB);
    expect($yearB->fresh()->is_current)->toBeTrue();
});
