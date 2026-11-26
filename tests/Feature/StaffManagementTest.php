<?php

use App\Actions\Staff\ChangeStaffRole;
use App\Actions\Staff\DisableMembership;
use App\Actions\Staff\EnableMembership;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Livewire\Staff\Index as StaffIndex;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Create a user with a membership and optional role in a school without leaking tenant state.
 *
 * @return array{user: User, membership: Membership}
 */
function createStaffMember(
    School $school,
    ?SchoolRole $role = SchoolRole::Teacher,
    MembershipStatus $status = MembershipStatus::Active,
    ?User $existingUser = null,
    array $userAttributes = [],
): array {
    test()->seed(PermissionSeeder::class);

    $user = $existingUser ?? User::factory()->create($userAttributes);

    $membership = Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => $status,
        'disabled_at' => $status === MembershipStatus::Disabled ? now() : null,
    ]);

    if ($role !== null) {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($school->id);
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->assignRole($role->value);
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        setPermissionsTeamId($previousTeamId);
    }

    return [
        'user' => $user,
        'membership' => $membership,
    ];
}

/**
 * Enter a school authorization context for Livewire component tests.
 */
function actInSchoolForStaff(User $user, School $school): void
{
    test()->actingAs($user);
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('enforces access control on the staff directory route across roles, non-members, and guests', function () {
    $this->seed(PermissionSeeder::class);
    $school = School::factory()->active()->create();

    $this->get(route('staff.index', ['school' => $school->id]))
        ->assertRedirect(route('login'));

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->get(route('staff.index', ['school' => $school->id]))
        ->assertNotFound();

    foreach ([SchoolRole::Teacher, SchoolRole::Accountant, SchoolRole::Parent, SchoolRole::Student] as $forbiddenRole) {
        ['user' => $member] = createStaffMember($school, $forbiddenRole);

        $this->actingAs($member)
            ->get(route('staff.index', ['school' => $school->id]))
            ->assertForbidden();
    }

    ['user' => $owner] = createStaffMember($school, SchoolRole::Owner);
    ['user' => $admin] = createStaffMember($school, SchoolRole::Admin);

    $this->actingAs($owner)
        ->get(route('staff.index', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Staff');

    $this->actingAs($admin)
        ->get(route('staff.index', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Staff');
});

it('allows inviting, resending, and revoking via the staff screen while enforcing role hierarchy and wildcard-safe search', function () {
    Notification::fake();

    $school = School::factory()->active()->create();
    ['user' => $owner] = createStaffMember($school, SchoolRole::Owner, userAttributes: ['name' => 'School Owner']);
    ['user' => $admin] = createStaffMember($school, SchoolRole::Admin, userAttributes: ['name' => 'School Admin']);
    ['user' => $teacher] = createStaffMember($school, SchoolRole::Teacher, userAttributes: ['name' => 'Rohan_Teacher']);
    createStaffMember($school, SchoolRole::Teacher, userAttributes: ['name' => 'RohanXTeacher']);
    createStaffMember($school, SchoolRole::Parent, userAttributes: ['name' => 'Parent ShouldNotAppear']);

    // Parents do not appear in the staff directory, and wildcard '_' / '%' searches are safely escaped.
    actInSchoolForStaff($owner, $school);

    Livewire::test(StaffIndex::class)
        ->assertSee('Rohan_Teacher')
        ->assertSee('RohanXTeacher')
        ->assertDontSee('Parent ShouldNotAppear')
        ->set('search', '%')
        ->assertDontSee('Rohan_Teacher')
        ->set('search', 'Rohan_')
        ->assertSee('Rohan_Teacher')
        ->assertDontSee('RohanXTeacher')
        ->set('search', '');

    // Owner can invite an Admin from the UI modal.
    Livewire::test(StaffIndex::class)
        ->call('openInviteModal')
        ->set('name', 'Neha Admin')
        ->set('email', 'neha.admin@school.in')
        ->set('role', SchoolRole::Admin->value)
        ->call('sendInvite')
        ->assertHasNoErrors();

    $adminInvitation = Invitation::where('email', 'neha.admin@school.in')->firstOrFail();

    // Admin sees only Accountant & Teacher assignable options, and forging Admin role fails validation.
    actInSchoolForStaff($admin, $school);

    $adminComponent = Livewire::test(StaffIndex::class)
        ->assertSee('Staff');

    expect($adminComponent->instance()->assignableRoles)->toBe([
        SchoolRole::Accountant,
        SchoolRole::Teacher,
    ]);

    $adminComponent
        ->set('name', 'Escalated Admin')
        ->set('email', 'escalate@school.in')
        ->set('role', SchoolRole::Admin->value)
        ->call('sendInvite')
        ->assertHasErrors(['role']);

    expect(Invitation::where('email', 'escalate@school.in')->exists())->toBeFalse();

    // Invitations tab shows Pending/Expired badges, hides copy link, and hides Admin-invite actions from Admin.
    $expiredTeacherInvite = Invitation::factory()->for($school)->expired()->create([
        'name' => 'Expired Teacher',
        'email' => 'expired.t@school.in',
        'role' => SchoolRole::Teacher,
    ]);

    Livewire::test(StaffIndex::class)
        ->call('setTab', 'invitations')
        ->assertSee('Neha Admin')
        ->assertSee('Pending')
        ->assertSee('Expired Teacher')
        ->assertSee('Expired')
        ->assertDontSee('Copy link')
        ->assertDontSee('data-test="resend-invitation-'.$adminInvitation->id.'"', false)
        ->assertDontSee('data-test="revoke-invitation-'.$adminInvitation->id.'"', false)
        ->assertSee('data-test="resend-invitation-'.$expiredTeacherInvite->id.'"', false)
        ->assertSee('data-test="revoke-invitation-'.$expiredTeacherInvite->id.'"', false)
        ->call('revokeInvitation', $expiredTeacherInvite->id);

    expect($expiredTeacherInvite->fresh()->isRevoked())->toBeTrue();
    expect($teacher->email)->not->toBeNull();
});

it('prevents Admins from managing Owners or Admins and blocks anyone from acting on themselves', function () {
    $school = School::factory()->active()->create();
    ['user' => $owner, 'membership' => $ownerMembership] = createStaffMember($school, SchoolRole::Owner);
    ['user' => $admin, 'membership' => $adminMembership] = createStaffMember($school, SchoolRole::Admin);
    ['user' => $otherAdmin, 'membership' => $otherAdminMembership] = createStaffMember($school, SchoolRole::Admin);
    ['user' => $teacher, 'membership' => $teacherMembership] = createStaffMember($school, SchoolRole::Teacher);

    // Admin sees action buttons for Teacher only (not Owner, self Admin, or other Admin).
    actInSchoolForStaff($admin, $school);

    Livewire::test(StaffIndex::class)
        ->assertDontSee('data-test="change-role-'.$ownerMembership->id.'"', false)
        ->assertDontSee('data-test="disable-member-'.$ownerMembership->id.'"', false)
        ->assertDontSee('data-test="change-role-'.$adminMembership->id.'"', false)
        ->assertDontSee('data-test="disable-member-'.$adminMembership->id.'"', false)
        ->assertDontSee('data-test="change-role-'.$otherAdminMembership->id.'"', false)
        ->assertDontSee('data-test="disable-member-'.$otherAdminMembership->id.'"', false)
        ->assertSee('data-test="change-role-'.$teacherMembership->id.'"', false)
        ->assertSee('data-test="disable-member-'.$teacherMembership->id.'"', false);

    foreach ([$ownerMembership, $adminMembership, $otherAdminMembership] as $protectedTarget) {
        Livewire::test(StaffIndex::class)
            ->call('openChangeRoleModal', $protectedTarget->id)
            ->assertForbidden();

        Livewire::test(StaffIndex::class)
            ->call('disableMember', $protectedTarget->id)
            ->assertForbidden();

        expect(fn () => app(ChangeStaffRole::class)->handle($admin, $protectedTarget, SchoolRole::Teacher))
            ->toThrow(AuthorizationException::class);

        expect(fn () => app(DisableMembership::class)->handle($admin, $protectedTarget))
            ->toThrow(AuthorizationException::class);
    }

    // Owner cannot act on themselves either.
    actInSchoolForStaff($owner, $school);

    Livewire::test(StaffIndex::class)
        ->assertDontSee('data-test="change-role-'.$ownerMembership->id.'"', false)
        ->assertDontSee('data-test="disable-member-'.$ownerMembership->id.'"', false)
        ->call('disableMember', $ownerMembership->id)
        ->assertForbidden();
});

it('prevents the sole active Owner from being demoted or disabled', function () {
    $school = School::factory()->active()->create();
    ['user' => $owner, 'membership' => $ownerMembership] = createStaffMember($school, SchoolRole::Owner);
    ['user' => $admin] = createStaffMember($school, SchoolRole::Admin);

    actInSchoolForStaff($owner, $school);

    expect(fn () => app(ChangeStaffRole::class)->handle($owner, $ownerMembership, SchoolRole::Admin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(DisableMembership::class)->handle($owner, $ownerMembership))
        ->toThrow(AuthorizationException::class);

    actInSchoolForStaff($admin, $school);

    expect(fn () => app(ChangeStaffRole::class)->handle($admin, $ownerMembership, SchoolRole::Teacher))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(DisableMembership::class)->handle($admin, $ownerMembership))
        ->toThrow(AuthorizationException::class);

    expect($ownerMembership->fresh()->isActive())->toBeTrue()
        ->and($owner->staffRoleForSchool($school->id))->toBe(SchoolRole::Owner);
});

it('changes only staff roles inside the current school and records old and new roles in audit log', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    ['user' => $owner] = createStaffMember($schoolA, SchoolRole::Owner);
    ['user' => $targetUser, 'membership' => $targetMembership] = createStaffMember($schoolA, SchoolRole::Teacher);

    // Give the user a Parent role in School A AND an Owner role in School B.
    setPermissionsTeamId($schoolA->id);
    $targetUser->unsetRelation('roles');
    $targetUser->assignRole(SchoolRole::Parent->value);

    createStaffMember($schoolB, SchoolRole::Owner, existingUser: $targetUser);

    actInSchoolForStaff($owner, $schoolA);

    Livewire::test(StaffIndex::class)
        ->call('openChangeRoleModal', $targetMembership->id)
        ->assertSet('editingMembershipId', $targetMembership->id)
        ->set('selectedRole', SchoolRole::Accountant->value)
        ->call('saveRoleChange')
        ->assertHasNoErrors();

    // Exactly one staff role (Accountant) remains in School A, while Parent in School A and Owner in School B stay intact.
    setPermissionsTeamId($schoolA->id);
    $targetUser->unsetRelation('roles');
    expect($targetUser->hasRole(SchoolRole::Accountant->value))->toBeTrue()
        ->and($targetUser->hasRole(SchoolRole::Teacher->value))->toBeFalse()
        ->and($targetUser->hasRole(SchoolRole::Parent->value))->toBeTrue()
        ->and($targetUser->staffRoleForSchool($schoolB->id))->toBe(SchoolRole::Owner);

    // Forged Owner role assignment by Owner is refused cleanly.
    Livewire::test(StaffIndex::class)
        ->call('openChangeRoleModal', $targetMembership->id)
        ->set('selectedRole', SchoolRole::Owner->value)
        ->call('saveRoleChange')
        ->assertHasErrors(['selectedRole']);

    $audit = Activity::where('event', 'role_changed')
        ->where('subject_id', $targetMembership->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->school_id)->toBe($schoolA->id)
        ->and($audit->causer_id)->toBe($owner->id)
        ->and($audit->properties->get('old_role'))->toBe('Teacher')
        ->and($audit->properties->get('new_role'))->toBe('Accountant');
});

it('disables a staff member with immediate 404 access revocation and restores the same role on re-enable', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    ['user' => $owner] = createStaffMember($schoolA, SchoolRole::Owner);
    ['user' => $staffUser, 'membership' => $membershipA] = createStaffMember($schoolA, SchoolRole::Teacher);
    createStaffMember($schoolB, SchoolRole::Admin, existingUser: $staffUser);

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk();

    // Disable in School A via Livewire screen.
    actInSchoolForStaff($owner, $schoolA);

    Livewire::test(StaffIndex::class)
        ->call('disableMember', $membershipA->id);

    expect($membershipA->fresh()->isActive())->toBeFalse()
        ->and($membershipA->fresh()->disabled_at)->not->toBeNull()
        ->and($staffUser->staffRoleForSchool($schoolA->id))->toBe(SchoolRole::Teacher);

    $disableAudit = Activity::where('event', 'membership_disabled')
        ->where('subject_id', $membershipA->id)
        ->first();
    expect($disableAudit)->not->toBeNull()
        ->and($disableAudit->school_id)->toBe($schoolA->id)
        ->and($disableAudit->causer_id)->toBe($owner->id);

    // Next request to School A immediately fails with 404, while School B remains accessible.
    CurrentSchool::forget();
    setPermissionsTeamId(null);

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertNotFound();

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['school' => $schoolB->id]))
        ->assertOk();

    // Owner switches to Disabled tab and re-enables the member, restoring access with the exact same role.
    actInSchoolForStaff($owner, $schoolA);

    Livewire::test(StaffIndex::class)
        ->call('setTab', 'disabled')
        ->assertSee($staffUser->name)
        ->call('enableMember', $membershipA->id);

    expect($membershipA->fresh()->isActive())->toBeTrue()
        ->and($membershipA->fresh()->disabled_at)->toBeNull()
        ->and($staffUser->staffRoleForSchool($schoolA->id))->toBe(SchoolRole::Teacher);

    $enableAudit = Activity::where('event', 'membership_enabled')
        ->where('subject_id', $membershipA->id)
        ->first();
    expect($enableAudit)->not->toBeNull()
        ->and($enableAudit->school_id)->toBe($schoolA->id)
        ->and($enableAudit->causer_id)->toBe($owner->id);

    CurrentSchool::forget();
    setPermissionsTeamId(null);

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk();
});

it('blocks cross-school membership and invitation forging over Livewire and actions and locks editingMembershipId', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    ['user' => $adminA] = createStaffMember($schoolA, SchoolRole::Admin);
    ['membership' => $teacherMembershipB] = createStaffMember($schoolB, SchoolRole::Teacher);
    ['membership' => $disabledMembershipB] = createStaffMember($schoolB, SchoolRole::Teacher, MembershipStatus::Disabled);

    CurrentSchool::set($schoolB);
    $invitationB = Invitation::factory()->for($schoolB)->pending()->create(['role' => SchoolRole::Teacher]);
    CurrentSchool::forget();

    actInSchoolForStaff($adminA, $schoolA);

    // Attempting to tamper locked editingMembershipId is rejected.
    expect(fn () => Livewire::test(StaffIndex::class)
        ->set('editingMembershipId', $teacherMembershipB->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    foreach (['openChangeRoleModal', 'disableMember', 'enableMember'] as $method) {
        $target = $method === 'enableMember' ? $disabledMembershipB->id : $teacherMembershipB->id;

        Livewire::test(StaffIndex::class)
            ->call($method, $target)
            ->assertNotFound();
    }

    foreach (['resendInvitation', 'revokeInvitation'] as $inviteMethod) {
        Livewire::test(StaffIndex::class)
            ->call($inviteMethod, $invitationB->id)
            ->assertNotFound();
    }

    expect(fn () => app(ChangeStaffRole::class)->handle($adminA, $teacherMembershipB, SchoolRole::Accountant))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => app(DisableMembership::class)->handle($adminA, $teacherMembershipB))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => app(EnableMembership::class)->handle($adminA, $disabledMembershipB))
        ->toThrow(ModelNotFoundException::class);

    expect($teacherMembershipB->fresh()->isActive())->toBeTrue()
        ->and($disabledMembershipB->fresh()->isActive())->toBeFalse()
        ->and($invitationB->fresh()->isRevoked())->toBeFalse();
});

it('executes a fixed number of database queries for the staff list whether a school has 5 or 50 staff', function () {
    $smallSchool = School::factory()->active()->create();
    $largeSchool = School::factory()->active()->create();

    ['user' => $smallOwner] = createStaffMember($smallSchool, SchoolRole::Owner);
    ['user' => $largeOwner] = createStaffMember($largeSchool, SchoolRole::Owner);

    for ($index = 1; $index <= 4; $index++) {
        createStaffMember($smallSchool, SchoolRole::Teacher, userAttributes: ['name' => "Small Teacher {$index}"]);
    }

    for ($index = 1; $index <= 49; $index++) {
        createStaffMember($largeSchool, SchoolRole::Teacher, userAttributes: ['name' => "Large Teacher {$index}"]);
    }

    // Warm caches with an initial render so both measurements reflect steady-state runtime queries.
    $this->actingAs($smallOwner)->get(route('staff.index', ['school' => $smallSchool->id]))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($smallOwner)->get(route('staff.index', ['school' => $smallSchool->id]))->assertOk();
    $smallQueryCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->actingAs($largeOwner)->get(route('staff.index', ['school' => $largeSchool->id]))->assertOk();
    $largeQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeQueryCount)
        ->toBe($smallQueryCount)
        ->toBeLessThanOrEqual(15);
});
