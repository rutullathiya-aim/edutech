<?php

use App\Enums\SchoolRole;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

it('redirects guests to login when visiting the school picker', function () {
    $this->get(route('schools.index'))
        ->assertRedirect(route('login'));
});

it('redirects unverified users to the verification notice when visiting the school picker', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertRedirect(route('verification.notice'));
});

it('shows an empty state when the user has zero active schools', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertOk()
        ->assertSee("You're not part of any school yet");
});

it('redirects straight to the school dashboard when the user has one active school', function () {
    $user = User::factory()->create();
    $school = School::factory()->active()->create();

    Membership::factory()->active()->for($user)->for($school)->create();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertRedirect(route('dashboard', ['school' => $school->id]));
});

it('lists active schools as plain links without roles when the user has several schools', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $schoolA = School::factory()->active()->create(['name' => 'Alpha Public School']);
    $schoolB = School::factory()->create([
        'name' => 'Beta Valley Academy',
        'trial_ends_at' => now()->addDays(14),
    ]);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();

    setPermissionsTeamId($schoolA->id);
    $user->assignRole(SchoolRole::Teacher->value);

    setPermissionsTeamId($schoolB->id);
    $user->assignRole(SchoolRole::Parent->value);
    setPermissionsTeamId(null);

    $response = $this->actingAs($user)->get(route('schools.index'));

    $response->assertOk()
        ->assertSee('Alpha Public School')
        ->assertSee('Beta Valley Academy')
        ->assertSee(route('dashboard', ['school' => $schoolA->id]), false)
        ->assertSee(route('dashboard', ['school' => $schoolB->id]), false)
        ->assertDontSee(SchoolRole::Teacher->value)
        ->assertDontSee(SchoolRole::Parent->value);
});

it('excludes inactive schools and disabled memberships when a user has only excluded schools', function () {
    $user = User::factory()->create();

    $suspendedSchool = School::factory()->suspended()->create(['name' => 'Suspended High']);
    $cancelledSchool = School::factory()->cancelled()->create(['name' => 'Cancelled Academy']);
    $expiredTrialSchool = School::factory()->expiredTrial()->create(['name' => 'Expired Trial School']);
    $openEndedTrialSchool = School::factory()->create([
        'name' => 'Open Ended Trial School',
        'trial_ends_at' => null,
    ]);
    $deletedSchool = School::factory()->active()->create(['name' => 'Deleted School']);
    $disabledMembershipSchool = School::factory()->active()->create(['name' => 'Disabled Member School']);

    foreach ([$suspendedSchool, $cancelledSchool, $expiredTrialSchool, $openEndedTrialSchool, $deletedSchool] as $school) {
        Membership::factory()->active()->for($user)->for($school)->create();
    }

    Membership::factory()->disabled()->for($user)->for($disabledMembershipSchool)->create();
    $deletedSchool->delete();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertOk()
        ->assertSee("You're not part of any school yet")
        ->assertDontSee('Suspended High')
        ->assertDontSee('Cancelled Academy')
        ->assertDontSee('Expired Trial School')
        ->assertDontSee('Open Ended Trial School')
        ->assertDontSee('Deleted School')
        ->assertDontSee('Disabled Member School');
});

it('ignores excluded schools and redirects when only one active school qualifies', function () {
    $user = User::factory()->create();

    $activeSchool = School::factory()->active()->create(['name' => 'Only Usable School']);
    $suspendedSchool = School::factory()->suspended()->create();
    $disabledMembershipSchool = School::factory()->active()->create();

    Membership::factory()->active()->for($user)->for($activeSchool)->create();
    Membership::factory()->active()->for($user)->for($suspendedSchool)->create();
    Membership::factory()->disabled()->for($user)->for($disabledMembershipSchool)->create();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertRedirect(route('dashboard', ['school' => $activeSchool->id]));
});

it('never shows another users active schools in the picker or switcher', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $schoolA = School::factory()->active()->create(['name' => 'Alpha School']);
    $schoolB = School::factory()->active()->create(['name' => 'Beta School']);
    $otherSchool = School::factory()->active()->create(['name' => 'Foreign Private School']);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();
    Membership::factory()->active()->for($otherUser)->for($otherSchool)->create();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertOk()
        ->assertSee('Alpha School')
        ->assertSee('Beta School')
        ->assertDontSee('Foreign Private School')
        ->assertDontSee(route('dashboard', ['school' => $otherSchool->id]), false);
});

it('redirects guests on /dashboard to login', function () {
    $this->get('/dashboard')
        ->assertRedirect(route('login'));
});

it('redirects authenticated users on /dashboard to the school picker', function () {
    $user = User::factory()->create();
    $school = School::factory()->active()->create();
    Membership::factory()->active()->for($user)->for($school)->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('schools.index'));
});

it('redirects to the school picker after login', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('schools.index', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('loads active schools at most once per request when rendering picker and sidebar switcher', function () {
    $user = User::factory()->create();
    $schoolA = School::factory()->active()->create(['name' => 'Alpha School']);
    $schoolB = School::factory()->active()->create(['name' => 'Beta School']);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertOk();

    $activeSchoolQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'schools') && str_contains($query['query'], 'memberships'));

    expect($activeSchoolQueries)->toHaveCount(1);
});

it('hides the sidebar school switcher when the user has zero active schools', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('data-test="school-switcher"', false);
});

it('hides the sidebar school switcher when the user has only one active school', function () {
    $user = User::factory()->create();
    $school = School::factory()->active()->create();

    Membership::factory()->active()->for($user)->for($school)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertDontSee('data-test="school-switcher"', false);
});

it('shows the sidebar switcher with the users active schools on school pages', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $schoolA = School::factory()->active()->create(['name' => 'Alpha School']);
    $schoolB = School::factory()->active()->create(['name' => 'Beta School']);
    $suspendedSchool = School::factory()->suspended()->create(['name' => 'Suspended School']);
    $otherSchool = School::factory()->active()->create(['name' => 'Other User School']);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();
    Membership::factory()->active()->for($user)->for($suspendedSchool)->create();
    Membership::factory()->active()->for($otherUser)->for($otherSchool)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $schoolA->id]))
        ->assertOk()
        ->assertSee('data-test="school-switcher"', false)
        ->assertSee('Alpha School')
        ->assertSee('Beta School')
        ->assertSee(route('dashboard', ['school' => $schoolA->id]), false)
        ->assertSee(route('dashboard', ['school' => $schoolB->id]), false)
        ->assertDontSee('Suspended School')
        ->assertDontSee('Other User School');
});

it('wires non-school dashboard links and switcher to the picker on profile and welcome pages', function () {
    $user = User::factory()->create();

    $schoolA = School::factory()->active()->create(['name' => 'Alpha School']);
    $schoolB = School::factory()->active()->create(['name' => 'Beta School']);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-test="school-switcher"', false)
        ->assertSee(route('schools.index'), false)
        ->assertSee('Alpha School')
        ->assertSee('Beta School');

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('schools.index'), false);
});

it('escapes school names rendered in the school picker', function () {
    $user = User::factory()->create();
    $schoolA = School::factory()->active()->create(['name' => "O'Reilly <script>alert('xss')</script>"]);
    $schoolB = School::factory()->active()->create(['name' => 'Normal Academy']);

    Membership::factory()->active()->for($user)->for($schoolA)->create();
    Membership::factory()->active()->for($user)->for($schoolB)->create();

    $content = $this->actingAs($user)
        ->get(route('schools.index'))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>");
});
