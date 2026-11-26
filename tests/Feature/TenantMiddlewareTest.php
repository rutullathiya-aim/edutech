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
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
            'name' => 'Autofill Staff',
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
            'name' => 'Cross Staff',
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
        'name' => 'No Tenant Staff',
        'email' => 'notenant@school.org',
        'role' => SchoolRole::Teacher,
    ]))->toThrow(LogicException::class, 'A school_id must be provided when creating a school-scoped model without an active tenant context.');
});

test('token lookup still works with no current school', function () {
    CurrentSchool::forget();

    $school = School::factory()->create();
    [$invitation, $rawToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'name' => 'Token Holder',
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
    Route::get('{school}/test-context-inspection', function () {
        return response()->json([
            'school_id' => CurrentSchool::id(),
            'team_id' => getPermissionsTeamId(),
            'default_route' => route('dashboard'),
        ]);
    })->whereUlid('school')
        ->middleware(['web', 'auth', 'verified', SetCurrentSchool::class]);

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
        ->get("/{$school->id}/test-context-inspection");

    $response->assertOk()
        ->assertJson([
            'school_id' => $school->id,
            'team_id' => $school->id,
            'default_route' => route('dashboard', ['school' => $school->id]),
        ]);

    // Request termination clears context and team ID (S2)
    expect(CurrentSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

test('SetCurrentSchool middleware is registered as Livewire persistent middleware', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(SetCurrentSchool::class);
});

test('route model binding on school-scoped records loads own school record with 200 and another school record with 404', function () {
    Route::get('{school}/test-invitations/{invitation}', function (School $school, Invitation $invitation) {
        return response()->json(['id' => $invitation->id]);
    })->whereUlid('school')
        ->middleware(['web', 'auth', 'verified', SetCurrentSchool::class]);

    $schoolA = School::factory()->create(['status' => SchoolStatus::Active]);
    $schoolB = School::factory()->create(['status' => SchoolStatus::Active]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $schoolA->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    [$invitationA] = Invitation::createWithToken([
        'school_id' => $schoolA->id,
        'name' => 'Invite A',
        'email' => 'invite_a@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    [$invitationB] = Invitation::createWithToken([
        'school_id' => $schoolB->id,
        'name' => 'Invite B',
        'email' => 'invite_b@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    // Own school record loads successfully (200)
    $this->actingAs($user)
        ->get("/{$schoolA->id}/test-invitations/{$invitationA->id}")
        ->assertOk()
        ->assertJson(['id' => $invitationA->id]);

    // Another school record is scoped out and returns 404, NOT 500
    $this->actingAs($user)
        ->get("/{$schoolA->id}/test-invitations/{$invitationB->id}")
        ->assertNotFound();
});

test('a guest opening a school URL is redirected to login', function () {
    $school = School::factory()->create(['status' => SchoolStatus::Active]);

    $this->get("/{$school->id}/dashboard")
        ->assertRedirect(route('login'));
});

test('root routes login, settings/profile, and dashboard resolution are not caught by school route', function () {
    $school = School::factory()->create(['status' => SchoolStatus::Active]);
    $user = User::factory()->create();
    Membership::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    // Guest login page
    $this->get('/login')->assertOk();

    // Authenticated profile page
    $this->actingAs($user)->get('/settings/profile')->assertOk();

    // Root /dashboard redirects to school picker
    $this->actingAs($user)->get('/dashboard')
        ->assertRedirect(route('schools.index'));
});

test('roles follow the URL across multiple schools', function () {
    $this->seed(RoleSeeder::class);

    Route::get('{school}/test-role-check', function () {
        return response()->json([
            'team_id' => getPermissionsTeamId(),
            'is_teacher' => auth()->user()?->hasRole(SchoolRole::Teacher->value),
            'is_parent' => auth()->user()?->hasRole(SchoolRole::Parent->value),
        ]);
    })->whereUlid('school')
        ->middleware(['web', 'auth', 'verified', SetCurrentSchool::class]);

    $schoolA = School::factory()->create(['status' => SchoolStatus::Active]);
    $schoolB = School::factory()->create(['status' => SchoolStatus::Active]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'school_id' => $schoolA->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    Membership::factory()->create([
        'school_id' => $schoolB->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    // In School A, user is Teacher
    setPermissionsTeamId($schoolA->id);
    $user->assignRole(SchoolRole::Teacher->value);

    // In School B, user is Parent
    setPermissionsTeamId($schoolB->id);
    $user->assignRole(SchoolRole::Parent->value);

    // Reset team ID before requests
    setPermissionsTeamId(null);

    // When requesting School A's dashboard / route:
    $this->actingAs($user)->get("/{$schoolA->id}/test-role-check")
        ->assertOk()
        ->assertJson([
            'team_id' => $schoolA->id,
            'is_teacher' => true,
            'is_parent' => false,
        ]);

    // When requesting School B's dashboard / route:
    $this->actingAs($user)->get("/{$schoolB->id}/test-role-check")
        ->assertOk()
        ->assertJson([
            'team_id' => $schoolB->id,
            'is_teacher' => false,
            'is_parent' => true,
        ]);

    // Request termination clears permissions team ID
    expect(getPermissionsTeamId())->toBeNull();
});
