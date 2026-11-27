<?php

use App\Actions\Attendance\AddHoliday;
use App\Actions\Attendance\RemoveHoliday;
use App\Actions\Attendance\UpdateWeeklyOffDays;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Livewire\Schools\Settings as SchoolsSettings;
use App\Livewire\Setup\Holidays as SetupHolidays;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Holiday;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    test()->seed(PermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    CurrentSchool::forget();
    setPermissionsTeamId(null);
});

/**
 * Provision an active school member with the given role for working-day tests.
 */
function createWorkingDaysMember(School $school, SchoolRole $role, ?string $name = null): User
{
    $user = User::factory()->create($name !== null ? ['name' => $name] : []);

    Membership::factory()
        ->state(['status' => MembershipStatus::Active])
        ->for($user)
        ->for($school)
        ->create();

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return $user;
}

test('School::today respects timezone across UTC date boundaries and weekly_off_days defaults to Sunday with mass-assignment protection', function () {
    CarbonImmutable::setTestNow('2026-11-26 23:30:00 UTC');

    $istSchool = School::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    $utcSchool = School::factory()->active()->create(['timezone' => 'UTC']);

    // 23:30 UTC on 26 Nov is 05:00 IST on 27 Nov.
    expect($istSchool->today()->toDateString())->toBe('2026-11-27')
        ->and($utcSchool->today()->toDateString())->toBe('2026-11-26');

    // Fresh school defaults weekly_off_days to [7] (Sunday) and detects off days correctly.
    expect($istSchool->weeklyOffDays())->toBe([7])
        ->and($istSchool->isWeeklyOff(CarbonImmutable::parse('2026-11-29')))->toBeTrue() // Sunday
        ->and($istSchool->isWeeklyOff(CarbonImmutable::parse('2026-11-28')))->toBeFalse() // Saturday
        ->and($istSchool->isWeeklyOff(CarbonImmutable::parse('2026-11-30')))->toBeFalse(); // Monday

    // Explicit empty array remains [] (never confused with null fallback [7]).
    $istSchool->weekly_off_days = [];
    $istSchool->save();
    $istSchool->refresh();

    expect($istSchool->weeklyOffDays())->toBe([])
        ->and($istSchool->isWeeklyOff(CarbonImmutable::parse('2026-11-29')))->toBeFalse();

    // Direct null falls back safely to [7].
    $istSchool->weekly_off_days = null;
    expect($istSchool->weeklyOffDays())->toBe([7]);

    // weekly_off_days is guarded against mass assignment.
    expect(fn () => School::create([
        'name' => 'Mass Assign Guard',
        'weekly_off_days' => [6, 7],
    ]))->toThrow(MassAssignmentException::class);

    expect(fn () => $istSchool->fill(['weekly_off_days' => [6, 7]]))
        ->toThrow(MassAssignmentException::class);
});

test('UpdateWeeklyOffDays enforces attendance.manage, validates weekday bounds, keeps one working day, and logs changes', function () {
    $school = School::factory()->active()->create();
    $owner = createWorkingDaysMember($school, SchoolRole::Owner);
    $admin = createWorkingDaysMember($school, SchoolRole::Admin);
    $teacher = createWorkingDaysMember($school, SchoolRole::Teacher);
    $accountant = createWorkingDaysMember($school, SchoolRole::Accountant);
    $parent = createWorkingDaysMember($school, SchoolRole::Parent);
    $student = createWorkingDaysMember($school, SchoolRole::Student);

    CurrentSchool::set($school);

    $action = app(UpdateWeeklyOffDays::class);

    foreach ([$teacher, $accountant, $parent, $student] as $unauthorized) {
        setPermissionsTeamId($school->id);
        expect(fn () => $action->handle($unauthorized, [6, 7]))->toThrow(AuthorizationException::class);
    }

    setPermissionsTeamId($school->id);

    // Rejects invalid bounds, duplicates, and marking every day off.
    expect(fn () => $action->handle($owner, [0, 7]))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($owner, [8]))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($owner, [7, 7]))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($owner, [1, 2, 3, 4, 5, 6, 7]))->toThrow(ValidationException::class);

    // Owner saves unsorted string/int weekdays -> stored sorted as [6, 7] with audit entry.
    $updated = $action->handle($owner, ['7', 6]);
    expect($updated->weeklyOffDays())->toBe([6, 7]);

    $logCountAfterSave = Activity::query()->where('event', 'weekly_off_days_updated')->count();
    expect($logCountAfterSave)->toBe(1);

    // Re-saving same days is idempotent with no duplicate log entry.
    $action->handle($admin, [6, 7]);
    expect(Activity::query()->where('event', 'weekly_off_days_updated')->count())->toBe(1);

    // Admin can configure 0 weekly off days (7-day schedule).
    $zeroOff = $action->handle($admin, []);
    expect($zeroOff->weeklyOffDays())->toBe([]);
});

test('holidays schema composite FKs prevent cross-school years, enforce school+date uniqueness, and guard closed years', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    CurrentSchool::set($schoolA);
    $yearA = AcademicYear::factory()->for($schoolA)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $closedYearA = AcademicYear::factory()->for($schoolA)->closed()->create([
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);

    CurrentSchool::set($schoolB);
    $yearB = AcademicYear::factory()->for($schoolB)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    // Composite FK (school_id, academic_year_id) refuses cross-school year link even when CurrentSchool matches schoolA.
    CurrentSchool::set($schoolA);
    $crossHoliday = new Holiday([
        'academic_year_id' => $yearB->id,
        'date' => '2026-08-15',
        'name' => 'Cross School Independence Day',
    ]);
    expect(fn () => $crossHoliday->save())->toThrow(QueryException::class);

    // Same calendar date succeeds once per school and rejects duplicates within the same school.
    $holidayA = Holiday::factory()->for($schoolA)->for($yearA)->create([
        'date' => '2026-08-15',
        'name' => 'Independence Day',
    ]);
    expect(fn () => Holiday::factory()->for($schoolA)->for($yearA)->create([
        'date' => '2026-08-15',
        'name' => 'Duplicate Date',
    ]))->toThrow(QueryException::class);

    CurrentSchool::set($schoolB);
    $holidayB = Holiday::factory()->for($schoolB)->for($yearB)->create([
        'date' => '2026-08-15',
        'name' => 'Independence Day',
    ]);
    expect($holidayB->date->toDateString())->toBe('2026-08-15');

    // Referential integrity blocks deleting a non-current academic year while holidays exist.
    CurrentSchool::set($schoolA);
    $deletableCandidateYear = AcademicYear::factory()->for($schoolA)->create([
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);
    Holiday::factory()->for($schoolA)->for($deletableCandidateYear)->create([
        'date' => '2027-08-15',
        'name' => 'FK Guard Holiday',
    ]);
    expect(fn () => $deletableCandidateYear->delete())->toThrow(QueryException::class);

    // Model guard rejects creating or saving a holiday in a closed academic year.
    expect(fn () => Holiday::create([
        'academic_year_id' => $closedYearA->id,
        'date' => '2025-08-15',
        'name' => 'Closed Year Holiday',
    ]))->toThrow(LogicException::class);

    $holidayA->academic_year_id = $closedYearA->id;
    expect(fn () => $holidayA->save())->toThrow(LogicException::class);
});

test('AddHoliday and RemoveHoliday enforce attendance.manage, date bounds, uniqueness, closed years, and tenant isolation', function () {
    $school = School::factory()->active()->create();
    $otherSchool = School::factory()->active()->create();

    $owner = createWorkingDaysMember($school, SchoolRole::Owner);
    $admin = createWorkingDaysMember($school, SchoolRole::Admin);
    $teacher = createWorkingDaysMember($school, SchoolRole::Teacher);
    $accountant = createWorkingDaysMember($school, SchoolRole::Accountant);
    $parent = createWorkingDaysMember($school, SchoolRole::Parent);
    $student = createWorkingDaysMember($school, SchoolRole::Student);
    $otherOwner = createWorkingDaysMember($otherSchool, SchoolRole::Owner);

    CurrentSchool::set($otherSchool);
    $otherYear = AcademicYear::factory()->for($otherSchool)->current()->create([
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    CurrentSchool::set($school);
    $openYear = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $closedYear = AcademicYear::factory()->for($school)->closed()->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);

    $addHoliday = app(AddHoliday::class);
    $removeHoliday = app(RemoveHoliday::class);

    foreach ([$teacher, $accountant, $parent, $student] as $unauthorized) {
        setPermissionsTeamId($school->id);
        expect(fn () => $addHoliday->handle($unauthorized, $openYear, '2026-08-15', 'Independence Day'))
            ->toThrow(AuthorizationException::class);
    }

    setPermissionsTeamId($school->id);

    // Date outside academic year bounds is rejected.
    expect(fn () => $addHoliday->handle($owner, $openYear, '2026-03-31', 'Too Early'))
        ->toThrow(ValidationException::class);
    expect(fn () => $addHoliday->handle($owner, $openYear, '2027-04-01', 'Too Late'))
        ->toThrow(ValidationException::class);

    // Closed academic year is rejected.
    expect(fn () => $addHoliday->handle($owner, $closedYear, '2025-08-15', 'Closed Year Holiday'))
        ->toThrow(ValidationException::class);

    // Disallowed leading '=' or '@' formula characters are rejected.
    expect(fn () => $addHoliday->handle($owner, $openYear, '2026-08-15', '=cmd|calc'))
        ->toThrow(ValidationException::class);

    // Cross-school year lookup fails closed via tenant scope.
    expect(fn () => $addHoliday->handle($owner, $otherYear, '2026-08-15', 'Other School Holiday'))
        ->toThrow(ModelNotFoundException::class);

    // Owner creates holiday cleanly inside year bounds (including inclusive start/end dates).
    $holiday = $addHoliday->handle($owner, $openYear, '2026-08-15', '  Independence Day  ');
    $boundaryHoliday = $addHoliday->handle($admin, $openYear, '2027-03-31', 'Year End Holiday');

    expect($holiday->name)->toBe('Independence Day')
        ->and($holiday->date->toDateString())->toBe('2026-08-15')
        ->and($boundaryHoliday->date->toDateString())->toBe('2027-03-31');

    // Duplicate date fails cleanly.
    expect(fn () => $addHoliday->handle($admin, $openYear, '2026-08-15', 'Another Holiday'))
        ->toThrow(ValidationException::class);

    // Unauthorized actors cannot remove holidays.
    expect(fn () => $removeHoliday->handle($teacher, $holiday))
        ->toThrow(AuthorizationException::class);

    // Closing the year afterwards blocks RemoveHoliday both in action and model guard.
    $openYear->closed_at = now();
    $openYear->is_current = false;
    $openYear->save();

    expect(fn () => $removeHoliday->handle($owner, $holiday))
        ->toThrow(ValidationException::class);
    expect(fn () => $holiday->fresh()->delete())
        ->toThrow(LogicException::class);

    // Admin removes open-year holiday cleanly (re-open by creating fresh year).
    $secondOpenYear = AcademicYear::factory()->for($school)->create([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
    ]);
    $removable = $addHoliday->handle($admin, $secondOpenYear, '2027-10-02', 'Gandhi Jayanti');
    $removeHoliday->handle($admin, $removable);

    expect(Holiday::query()->whereKey($removable->id)->exists())->toBeFalse();

    // Cross-school holiday removal is blocked by ScopedBySchool.
    CurrentSchool::set($otherSchool);
    setPermissionsTeamId($otherSchool->id);
    $otherHoliday = $addHoliday->handle($otherOwner, $otherYear, '2026-08-15', 'Other Holiday');

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);
    expect(fn () => $removeHoliday->handle($owner, $otherHoliday))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($owner)
        ->test(SetupHolidays::class)
        ->call('removeHoliday', $otherHoliday->id)
        ->assertNotFound();
});

test('AddHoliday rejects a date where attendance_sessions already exist')
    ->todo('Cover attendance_sessions date conflict once Step 3 creates the attendance_sessions table.');

test('Settings and Holidays screens enforce route permissions, sidebar visibility, and full UI workflows', function () {
    $school = School::factory()->active()->create([
        'name' => 'Sunrise Public School',
        'timezone' => 'Asia/Kolkata',
    ]);
    $otherSchool = School::factory()->active()->create();

    $owner = createWorkingDaysMember($school, SchoolRole::Owner);
    $admin = createWorkingDaysMember($school, SchoolRole::Admin);
    $teacher = createWorkingDaysMember($school, SchoolRole::Teacher);
    $accountant = createWorkingDaysMember($school, SchoolRole::Accountant);
    $parent = createWorkingDaysMember($school, SchoolRole::Parent);
    $student = createWorkingDaysMember($school, SchoolRole::Student);
    $outsider = createWorkingDaysMember($otherSchool, SchoolRole::Owner);

    CurrentSchool::set($school);
    $openYear = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);
    $closedYear = AcademicYear::factory()->for($school)->closed()->create([
        'name' => '2025-26',
        'starts_on' => '2025-04-01',
        'ends_on' => '2026-03-31',
    ]);

    $settingsUrl = route('schools.settings', ['school' => $school->id]);
    $holidaysUrl = route('setup.holidays', ['school' => $school->id]);

    // Owner and Admin access both screens and see sidebar links.
    $this->actingAs($owner)->get($settingsUrl)
        ->assertOk()
        ->assertSee('School settings')
        ->assertSee('Asia/Kolkata')
        ->assertSee('Holidays');

    $this->actingAs($admin)->get($holidaysUrl)
        ->assertOk()
        ->assertSee('Holidays')
        ->assertSee($settingsUrl, false);

    // Teacher can view Holidays (read-only) without School settings link, and is 403 on Settings.
    $this->actingAs($teacher)->get($holidaysUrl)
        ->assertOk()
        ->assertSee('Holidays')
        ->assertDontSee($settingsUrl, false)
        ->assertDontSee('data-test="add-holiday-button"', false);

    $this->actingAs($teacher)->get($settingsUrl)->assertForbidden();

    // Accountant, Parent, Student are 403 on both; outsider is 404.
    foreach ([$accountant, $parent, $student] as $denied) {
        $this->actingAs($denied)->get($settingsUrl)->assertForbidden();
        $this->actingAs($denied)->get($holidaysUrl)->assertForbidden();
    }

    $this->actingAs($outsider)->get($settingsUrl)->assertNotFound();
    $this->actingAs($outsider)->get($holidaysUrl)->assertNotFound();

    // Livewire Schools\Settings workflow.
    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    Livewire::actingAs($owner)
        ->test(SchoolsSettings::class)
        ->assertSet('weeklyOffMap.7', true)
        ->assertSet('weeklyOffMap.6', false)
        ->set('weeklyOffMap.6', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($school->fresh()->weeklyOffDays())->toBe([6, 7]);

    // Selecting all 7 weekdays as off shows validation error.
    $allOffLw = Livewire::actingAs($owner)->test(SchoolsSettings::class);
    foreach (range(1, 7) as $day) {
        $allOffLw->set("weeklyOffMap.{$day}", true);
    }
    $allOffLw->call('save')->assertHasErrors(['weekly_off_days']);

    // Livewire Setup\Holidays workflow: add, validate date bounds, remove, and closed-year read-only mode.
    $holidayLw = Livewire::actingAs($owner)
        ->test(SetupHolidays::class)
        ->assertSee('2026-27')
        ->set('date', '2026-03-15')
        ->set('name', 'Outside Year')
        ->call('addHoliday')
        ->assertHasErrors(['date'])
        ->set('date', '2026-08-15')
        ->set('name', 'Independence Day')
        ->call('addHoliday')
        ->assertHasNoErrors()
        ->assertSee('15/08/2026')
        ->assertSee('Saturday')
        ->assertSee('Independence Day');

    $createdHoliday = Holiday::query()->where('name', 'Independence Day')->firstOrFail();

    // Switching to closed year displays read-only notice and hides add/remove buttons.
    $holidayLw->set('academic_year_id', $closedYear->id)
        ->assertSee('data-test="closed-year-notice"', false)
        ->assertDontSee('data-test="add-holiday-button"', false);

    // Switch back to open year and remove the holiday.
    $holidayLw->set('academic_year_id', $openYear->id)
        ->call('removeHoliday', $createdHoliday->id)
        ->assertHasNoErrors();

    expect(Holiday::query()->whereKey($createdHoliday->id)->exists())->toBeFalse();

    // Teacher cannot invoke Livewire addHoliday mutation directly.
    Livewire::actingAs($teacher)
        ->test(SetupHolidays::class)
        ->set('date', '2026-10-02')
        ->set('name', 'Gandhi Jayanti')
        ->call('addHoliday')
        ->assertForbidden();
});
