<?php

use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Exceptions\NoCurrentSchoolException;
use App\Http\Middleware\SetCurrentSchool;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('querying a school-scoped model with no current school throws NoCurrentSchoolException', function () {
    CurrentSchool::forget();

    expect(fn () => Invitation::count())->toThrow(NoCurrentSchoolException::class);
});

test('creating a school-scoped model inside a tenant context auto-fills school_id', function () {
    $school = School::factory()->create();
    CurrentSchool::set($school);

    try {
        [$invitation, $token] = Invitation::createWithToken([
            'email' => 'autofill@school.org',
            'role' => SchoolRole::Teacher,
        ]);

        expect($invitation->school_id)->toBe($school->id);
    } finally {
        CurrentSchool::forget();
    }
});

test('cross-school write is refused when creating for a different school than current tenant', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    CurrentSchool::set($schoolA);

    try {
        expect(fn () => Invitation::createWithToken([
            'school_id' => $schoolB->id,
            'email' => 'cross@school.org',
            'role' => SchoolRole::Teacher,
        ]))->toThrow(LogicException::class, 'Cross-school write refused');
    } finally {
        CurrentSchool::forget();
    }
});

test('creating a school-scoped model outside tenant context without explicit school_id fails', function () {
    CurrentSchool::forget();

    expect(fn () => Invitation::createWithToken([
        'email' => 'notenant@school.org',
        'role' => SchoolRole::Teacher,
    ]))->toThrow(LogicException::class, 'A school_id must be provided when creating a school-scoped model without an active tenant context.');
});

test('token lookup still works with no current school', function () {
    CurrentSchool::forget();

    $school = School::factory()->create();
    [$invitation, $rawToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'tokenholder@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    $found = Invitation::findPendingByToken($rawToken);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($invitation->id);
});

test('queued job dispatched during a school request inherits CurrentSchool id through context', function () {
    config(['queue.default' => 'database']);

    $school = School::factory()->create();
    CurrentSchool::set($school);

    Queue::push(function () {
        // Dummy closure job
    });

    CurrentSchool::forget();
    Context::flush();

    $jobRecord = DB::table('jobs')->latest('id')->first();
    expect($jobRecord)->not->toBeNull();

    /** @var array{illuminate:log:context?: array{data: array<string, mixed>, hidden: array<string, mixed>}} $payload */
    $payload = json_decode((string) $jobRecord->payload, true);

    expect($payload)->toHaveKey('illuminate:log:context');

    // Hydrating context reproduces the tenant ID inside the queue worker
    Context::hydrate($payload['illuminate:log:context']);

    expect(CurrentSchool::id())->toBe($school->id);

    CurrentSchool::forget();
    Context::flush();
});

test('visiting tenant route with non-ulid returns 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/not-a-ulid-string/dashboard')
        ->assertNotFound();
});

test('visiting tenant route with non-existent school ulid returns 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/01HZX9Y8B7C6D5E4F3G2H1J0K0/dashboard')
        ->assertNotFound();
});

test('visiting tenant route with soft-deleted school returns 404', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $school->delete();

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertNotFound();
});

test('visiting tenant route without membership returns 404 to prevent tenant enumeration', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertNotFound();
});

test('visiting tenant route with disabled membership returns 404', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Disabled,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertNotFound();
});

test('visiting tenant route when school is inactive returns school unavailable page with 403', function () {
    $school = School::factory()->create([
        'status' => SchoolStatus::Suspended,
    ]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]));

    $response->assertStatus(403);
    $response->assertSee('School Unavailable');
});

test('visiting tenant route with active school and membership sets CurrentSchool, Spatie team ID, and URL default', function () {
    $school = School::factory()->create([
        'status' => SchoolStatus::Active,
    ]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]));

    $response->assertOk();

    expect(CurrentSchool::id())->toBe($school->id)
        ->and(getPermissionsTeamId())->toBe($school->id)
        ->and(route('dashboard'))->toContain($school->id);

    CurrentSchool::forget();
});

test('SetCurrentSchool middleware is registered as Livewire persistent middleware', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(SetCurrentSchool::class);
});
