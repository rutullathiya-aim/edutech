<?php

use App\Models\Activity;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a school-owned subject gets that schools school_id in the activity log', function () {
    $school = School::factory()->create();
    $membership = Membership::factory()->create([
        'school_id' => $school->id,
    ]);

    $activity = activity()
        ->performedOn($membership)
        ->event('membership_created')
        ->log('Membership created');

    expect($activity->school_id)->toBe($school->id)
        ->and($activity->fresh()->school_id)->toBe($school->id);
});

test('passing a different school_id in the log call is overwritten with the subjects school_id', function () {
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $membership = Membership::factory()->create([
        'school_id' => $school->id,
    ]);

    $activity = activity()
        ->performedOn($membership)
        ->tap(function (Activity $act) use ($otherSchool) {
            $act->school_id = $otherSchool->id;
        })
        ->log('Forged school test');

    expect($activity->school_id)->toBe($school->id)
        ->and($activity->fresh()->school_id)->toBe($school->id);
});

test('a login event has school_id null', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $activity = Activity::where('event', 'login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBeNull()
        ->and($activity->causer_id)->toBe($user->id);
});

test('updating or deleting an activity log entry is refused', function () {
    $school = School::factory()->create();
    $membership = Membership::factory()->create([
        'school_id' => $school->id,
    ]);

    $activity = activity()
        ->performedOn($membership)
        ->log('Immutable log');

    expect(fn () => $activity->update(['description' => 'Tampered']))
        ->toThrow(LogicException::class, 'Activity logs are append-only and cannot be updated.');

    expect(fn () => $activity->delete())
        ->toThrow(LogicException::class, 'Activity logs are append-only and cannot be deleted.');
});

test('after a password change no password or hash appears in properties or attribute_changes', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $user->update([
        'password' => Hash::make('new-password'),
    ]);

    $activity = Activity::where('event', 'password_changed')
        ->where('subject_id', $user->id)
        ->latest()
        ->first();

    expect($activity)->not->toBeNull();

    $propertiesString = json_encode($activity->properties);
    $changesString = json_encode($activity->attribute_changes);

    expect($propertiesString)->not->toContain('old-password')
        ->and($propertiesString)->not->toContain('new-password')
        ->and($changesString)->not->toContain('old-password')
        ->and($changesString)->not->toContain('new-password')
        ->and($propertiesString)->not->toContain('$2y$')
        ->and($changesString)->not->toContain('$2y$');
});

test('a failed login is logged without the attempted password', function () {
    $this->post(route('login'), [
        'email' => 'unknown@school.org',
        'password' => 'secret-super-password',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBeNull();

    $properties = $activity->properties;
    expect($properties)->not->toBeNull()
        ->and($properties->get('email'))->toBe('unknown@school.org')
        ->and($properties->has('password'))->toBeFalse();

    $encoded = json_encode($activity->toArray());
    expect($encoded)->not->toContain('secret-super-password');
});
