<?php

use App\Enums\MembershipStatus;
use App\Enums\SchoolStatus;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('membership generates a 26 character ulid id', function () {
    $membership = Membership::factory()->create();

    expect($membership->id)->toBeString()
        ->and(strlen($membership->id))->toBe(26)
        ->and(Str::isUlid($membership->id))->toBeTrue();
});

test('the same user cannot have two memberships in one school', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);

    $this->expectException(QueryException::class);

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);
});

test('the same user can belong to two different schools', function () {
    $user = User::factory()->create();
    $school1 = School::factory()->create();
    $school2 = School::factory()->create();

    $m1 = Membership::factory()->create([
        'school_id' => $school1->id,
        'user_id' => $user->id,
    ]);

    $m2 = Membership::factory()->create([
        'school_id' => $school2->id,
        'user_id' => $user->id,
    ]);

    expect($user->memberships)->toHaveCount(2)
        ->and($user->schools)->toHaveCount(2);
});

test('deleting a user removes their memberships via cascade on delete', function () {
    $user = User::factory()->create();
    $school = School::factory()->create();

    $membership = Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);

    expect(Membership::find($membership->id))->not->toBeNull();

    $user->delete();

    expect(Membership::find($membership->id))->toBeNull();
});

test('a hard delete of a school that still has memberships is blocked', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);

    $this->expectException(QueryException::class);

    // Hard/force delete must be restricted by foreign key
    $school->forceDelete();
});

test('soft deleting a school retains memberships', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    $membership = Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);

    $school->delete(); // Soft delete

    expect($school->trashed())->toBeTrue()
        ->and(Membership::find($membership->id))->not->toBeNull();
});

test('school_id, user_id, status and disabled_at are protected against mass assignment', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    expect(fn () => Membership::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'joined_at' => now(),
        'status' => MembershipStatus::Disabled->value,
        'disabled_at' => now(),
    ]))->toThrow(MassAssignmentException::class);

    // Creating with only fillable attributes works
    $membership = new Membership([
        'joined_at' => now(),
    ]);
    $membership->school_id = $school->id;
    $membership->user_id = $user->id;
    $membership->save();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->disabled_at)->toBeNull();

    // Explicit method works
    $membership->disable();

    $refreshed = $membership->fresh();
    expect($refreshed->status)->toBe(MembershipStatus::Disabled)
        ->and($refreshed->disabled_at)->not->toBeNull();
});

test('relationships between school, user, and membership function properly', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    $membership = Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
    ]);

    expect($membership->school->id)->toBe($school->id)
        ->and($membership->user->id)->toBe($user->id)
        ->and($school->memberships->first()->id)->toBe($membership->id)
        ->and($school->users->first()->id)->toBe($user->id)
        ->and($user->memberships->first()->id)->toBe($membership->id)
        ->and($user->schools->first()->id)->toBe($school->id);
});

test('factory states for active and disabled work properly', function () {
    $active = Membership::factory()->active()->create();
    expect($active->status)->toBe(MembershipStatus::Active)
        ->and($active->disabled_at)->toBeNull();

    $disabled = Membership::factory()->disabled()->create();
    expect($disabled->status)->toBe(MembershipStatus::Disabled)
        ->and($disabled->disabled_at)->not->toBeNull();
});

test('updating a membership preserves joined_at date', function () {
    $thirtyDaysAgo = now()->subDays(30);

    $membership = Membership::factory()->create([
        'joined_at' => $thirtyDaysAgo,
    ]);

    $membership->disable();

    $refreshed = $membership->fresh();
    expect($refreshed->joined_at->isSameDay($thirtyDaysAgo))->toBeTrue()
        ->and($refreshed->disabled_at)->not->toBeNull();
});

test('school_id cannot be changed once a membership is created', function () {
    $membership = Membership::factory()->create();
    $otherSchool = School::factory()->create();

    $membership->school_id = $otherSchool->id;

    expect(fn () => $membership->save())->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

test('membership isActive helper and active scope work correctly', function () {
    $active = Membership::factory()->active()->create();
    $disabled = Membership::factory()->disabled()->create();

    expect($active->isActive())->toBeTrue()
        ->and($disabled->isActive())->toBeFalse();

    $activeIds = Membership::active()->pluck('id');

    expect($activeIds)->toContain($active->id)
        ->and($activeIds)->not->toContain($disabled->id);
});

test('User::activeSchools excludes suspended, cancelled, expired-trial, soft-deleted, and disabled-membership schools', function () {
    $user = User::factory()->create();

    // 1. Active school (included)
    $activeSchool = School::factory()->create(['status' => SchoolStatus::Active]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $activeSchool->id, 'status' => MembershipStatus::Active]);

    // 2. Trial school with future trial date (included)
    $futureTrialSchool = School::factory()->create([
        'status' => SchoolStatus::Trial,
        'trial_ends_at' => now()->addDays(14),
    ]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $futureTrialSchool->id, 'status' => MembershipStatus::Active]);

    // 3. Trial school with null trial date (included)
    $nullTrialSchool = School::factory()->create([
        'status' => SchoolStatus::Trial,
        'trial_ends_at' => null,
    ]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $nullTrialSchool->id, 'status' => MembershipStatus::Active]);

    // 4. Suspended school (excluded)
    $suspendedSchool = School::factory()->create(['status' => SchoolStatus::Suspended]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $suspendedSchool->id, 'status' => MembershipStatus::Active]);

    // 5. Cancelled school (excluded)
    $cancelledSchool = School::factory()->create(['status' => SchoolStatus::Cancelled]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $cancelledSchool->id, 'status' => MembershipStatus::Active]);

    // 6. Expired trial school (excluded)
    $expiredTrialSchool = School::factory()->create([
        'status' => SchoolStatus::Trial,
        'trial_ends_at' => now()->subDay(),
    ]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $expiredTrialSchool->id, 'status' => MembershipStatus::Active]);

    // 7. Soft-deleted school (excluded)
    $softDeletedSchool = School::factory()->create(['status' => SchoolStatus::Active]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $softDeletedSchool->id, 'status' => MembershipStatus::Active]);
    $softDeletedSchool->delete();

    // 8. Active school with disabled membership (excluded)
    $disabledMembershipSchool = School::factory()->create(['status' => SchoolStatus::Active]);
    Membership::factory()->create(['user_id' => $user->id, 'school_id' => $disabledMembershipSchool->id, 'status' => MembershipStatus::Disabled]);

    $activeSchools = $user->activeSchools()->get();
    $activeSchoolIds = $activeSchools->pluck('id');

    expect($activeSchools)->toHaveCount(3)
        ->and($activeSchoolIds)->toContain($activeSchool->id)
        ->and($activeSchoolIds)->toContain($futureTrialSchool->id)
        ->and($activeSchoolIds)->toContain($nullTrialSchool->id)
        ->and($activeSchoolIds)->not->toContain($suspendedSchool->id)
        ->and($activeSchoolIds)->not->toContain($cancelledSchool->id)
        ->and($activeSchoolIds)->not->toContain($expiredTrialSchool->id)
        ->and($activeSchoolIds)->not->toContain($softDeletedSchool->id)
        ->and($activeSchoolIds)->not->toContain($disabledMembershipSchool->id);
});
