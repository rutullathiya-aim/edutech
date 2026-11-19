<?php

use App\Livewire\Settings\Security;
use App\Models\Activity;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

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

test('a School model as subject sets school_id to the schools own id', function () {
    $school = School::factory()->create();

    $activity = activity()
        ->performedOn($school)
        ->event('school_updated')
        ->log('School settings updated');

    expect($activity->school_id)->toBe($school->id)
        ->and($activity->fresh()->school_id)->toBe($school->id);
});

test('a web request records ip_address and user_agent on activity logs', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
    ]);

    $this->withServerVariables([
        'REMOTE_ADDR' => '192.168.1.100',
        'HTTP_USER_AGENT' => 'TestBrowser/1.0',
    ])->post(route('login'), [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $activity = Activity::where('event', 'login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->ip_address)->toBe('192.168.1.100')
        ->and($activity->user_agent)->toBe('TestBrowser/1.0');
});

test('after a password change no password or hash appears in properties or attribute_changes', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(Security::class)
        ->set('current_password', 'old-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

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

test('a failed login with an existing user logs that user as the subject without typed input in properties', function () {
    $user = User::factory()->create([
        'email' => 'teacher@school.org',
        'password' => Hash::make('correct-password'),
    ]);

    $this->post(route('login'), [
        'email' => 'teacher@school.org',
        'password' => 'wrong-password',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(User::class)
        ->and($activity->subject_id)->toBe($user->id);

    $encoded = json_encode($activity->toArray());
    expect($encoded)->not->toContain('wrong-password')
        ->and($activity->properties)->toBeEmpty();
});

test('a failed login with an unknown email stores only a masked email', function () {
    $this->post(route('login'), [
        'email' => 'stranger@example.com',
        'password' => 'wrong-password',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBeNull()
        ->and($activity->properties->get('attempted_email'))->toBe('st***@example.com');

    $encoded = json_encode($activity->toArray());
    expect($encoded)->not->toContain('stranger@example.com')
        ->and($encoded)->not->toContain('wrong-password');
});

test('a failed login with a password-like string in the email field leaves no trace of that string in the log', function () {
    $leakedPassword = 'SuperSecretAccidentalPassword#123';

    $this->post(route('login'), [
        'email' => $leakedPassword,
        'password' => 'some-password',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull();

    $encoded = json_encode($activity->toArray());
    expect($encoded)->not->toContain($leakedPassword)
        ->and($encoded)->not->toContain('some-password');
});

test('an activity logged inside a school request with a subject that has no school_id gets the current schools ID', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    CurrentSchool::set($school);

    try {
        $activity = activity()
            ->performedOn($user)
            ->event('user_action')
            ->log('User updated in school context');

        expect($activity->school_id)->toBe($school->id)
            ->and($activity->fresh()->school_id)->toBe($school->id);
    } finally {
        CurrentSchool::forget();
    }
});

test('CurrentSchool uses hidden Context that is not exposed to Context::has', function () {
    $school = School::factory()->create();

    CurrentSchool::set($school);

    expect(Context::has('tenant_school'))->toBeFalse()
        ->and(CurrentSchool::check())->toBeTrue()
        ->and(CurrentSchool::get()?->id)->toBe($school->id)
        ->and(CurrentSchool::id())->toBe($school->id);

    CurrentSchool::forget();

    expect(CurrentSchool::check())->toBeFalse()
        ->and(CurrentSchool::get())->toBeNull()
        ->and(CurrentSchool::id())->toBeNull();
});

test('ip_address and user_agent are always overwritten during a web request even if manually passed', function () {
    $user = User::factory()->create();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_USER_AGENT' => 'ActualAgent/2.0',
    ])->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-pass',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->ip_address)->toBe('10.0.0.1')
        ->and($activity->user_agent)->toBe('ActualAgent/2.0');
});

test('user_agent length is capped at 500 characters', function () {
    $longUserAgent = str_repeat('A', 800);

    $this->withServerVariables([
        'HTTP_USER_AGENT' => $longUserAgent,
    ])->post(route('login'), [
        'email' => 'nobody@school.org',
        'password' => 'wrong-pass',
    ]);

    $activity = Activity::where('event', 'failed_login')->latest()->first();

    expect($activity)->not->toBeNull()
        ->and(strlen((string) $activity->user_agent))->toBe(500)
        ->and($activity->user_agent)->toBe(substr($longUserAgent, 0, 500));
});
