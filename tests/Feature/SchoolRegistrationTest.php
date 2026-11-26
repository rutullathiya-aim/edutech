<?php

use App\Actions\Schools\RegisterSchool;
use App\Enums\IndianState;
use App\Enums\MembershipStatus;
use App\Enums\SchoolBoard;
use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Livewire\Schools\Register;
use App\Models\Activity;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Valid registration payload helper for school registration tests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validSchoolRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'school_name' => 'Nalanda Public School',
        'state' => IndianState::Gujarat->value,
        'city' => 'Ahmedabad',
        'board' => SchoolBoard::Cbse->value,
        'name' => 'Aarav Mehta',
        'email' => 'aarav@nalanda.edu.in',
        'mobile' => '9876543210',
        'password' => 'SecurePass#2026',
        'password_confirmation' => 'SecurePass#2026',
        'terms' => true,
        'contact_hp_x9k' => '',
    ], $overrides);
}

it('renders the school registration screen for guests', function () {
    $this->get(route('schools.register'))
        ->assertOk()
        ->assertSee('Register your school');
});

it('redirects authenticated users away from school registration to /schools', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('schools.register'))
        ->assertRedirect(route('schools.index'));
});

it('registers a school, owner, membership, Owner role, audit log, and queues verification email', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $otherSchool = School::factory()->active()->create();

    $component = Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload())
        ->call('register');

    $component->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    /** @var User|null $user */
    $user = User::where('email', 'aarav@nalanda.edu.in')->first();
    /** @var School|null $school */
    $school = School::where('name', 'Nalanda Public School')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->mobile)->toBe('+919876543210')
        ->and($school)->not->toBeNull()
        ->and($school->status)->toBe(SchoolStatus::Trial)
        ->and($school->state)->toBe(IndianState::Gujarat)
        ->and($school->city)->toBe('Ahmedabad')
        ->and($school->board)->toBe(SchoolBoard::Cbse)
        ->and($school->trial_ends_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'))
        ->toBe(now('Asia/Kolkata')->addDays(30)->format('Y-m-d 23:59:59'));

    $membership = Membership::where('school_id', $school->id)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->status)->toBe(MembershipStatus::Active);

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    expect($user->hasRole(SchoolRole::Owner->value))->toBeTrue();

    setPermissionsTeamId($otherSchool->id);
    $user->unsetRelation('roles');
    expect($user->hasRole(SchoolRole::Owner->value))->toBeFalse();
    setPermissionsTeamId(null);

    /** @var Activity|null $activity */
    $activity = Activity::where('event', 'school_registered')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->school_id)->toBe($school->id)
        ->and($activity->subject_id)->toBe($school->id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties?->get('terms_version'))->toBe(config('schools.terms_version'))
        ->and($activity->properties?->get('terms_accepted_at'))->not->toBeNull()
        ->and(json_encode($activity->toArray()))->not->toContain('SecurePass#2026');

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('allows optional education board to be omitted during registration', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload(['board' => '']))
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    $school = School::where('name', 'Nalanda Public School')->first();

    expect($school)->not->toBeNull()
        ->and($school->board)->toBeNull();
});

it('queues an encrypted verification email payload on registration', function () {
    $this->seed(RoleSeeder::class);
    config(['queue.default' => 'database']);

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload(['email' => 'encrypted_owner@nalanda.edu.in']))
        ->call('register')
        ->assertHasNoErrors();

    $job = DB::table('jobs')->latest('id')->first();
    $this->assertNotNull($job);

    /** @var array{data: array{command: string}} $payload */
    $payload = json_decode((string) $job->payload, true);
    $decrypted = unserialize(decrypt($payload['data']['command']));

    expect($decrypted)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($decrypted->notification)->toBeInstanceOf(VerifyEmailNotification::class)
        ->and((string) $job->payload)->not->toContain('encrypted_owner@nalanda.edu.in');
});

it('rejects invalid school registration fields', function (string $field, mixed $value) {
    $this->seed(RoleSeeder::class);
    User::factory()->create(['email' => 'taken@nalanda.edu.in']);

    $overrides = [$field => $value];
    if ($field === 'password') {
        $overrides['password_confirmation'] = $value;
    }

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload($overrides))
        ->call('register')
        ->assertHasErrors([$field]);

    $this->assertDatabaseCount('schools', 0);
})->with([
    'missing school name' => ['school_name', ''],
    'missing city' => ['city', ''],
    'invalid state' => ['state', 'California'],
    'invalid mobile' => ['mobile', '12345'],
    'weak password' => ['password', 'short'],
    'duplicate email' => ['email', 'taken@nalanda.edu.in'],
    'terms not accepted' => ['terms', false],
]);

it('rolls back user, school, membership, and audit entries when roles are not seeded', function () {
    Notification::fake();

    $action = app(RegisterSchool::class);

    expect(fn () => $action->handle(validSchoolRegistrationPayload()))
        ->toThrow(RoleDoesNotExist::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('schools', 0);
    $this->assertDatabaseCount('memberships', 0);
    $this->assertDatabaseCount('activity_log', 0);
    expect(getPermissionsTeamId())->toBeNull();
    Notification::assertNothingSent();
});

it('ignores mass-assignment attempts for status, trial_ends_at, and student_limit', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $action = app(RegisterSchool::class);

    ['school' => $school, 'user' => $user] = $action->handle(validSchoolRegistrationPayload([
        'status' => SchoolStatus::Active->value,
        'trial_ends_at' => now()->addYears(10)->toDateTimeString(),
        'student_limit' => 9999,
        'is_platform_admin' => true,
    ]));

    expect($school->status)->toBe(SchoolStatus::Trial)
        ->and($school->trial_ends_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'))
        ->toBe(now('Asia/Kolkata')->addDays(30)->format('Y-m-d 23:59:59'))
        ->and($school->student_limit)->toBeNull()
        ->and($user->is_platform_admin)->toBeFalse();
});

it('resets Spatie permissions team ID back to null after RegisterSchool action completes', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    setPermissionsTeamId(null);

    app(RegisterSchool::class)->handle(validSchoolRegistrationPayload());

    expect(getPermissionsTeamId())->toBeNull();
});

it('does not block a fourth valid attempt after three validation failures', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        Livewire::test(Register::class)
            ->set(validSchoolRegistrationPayload(['mobile' => '12345']))
            ->call('register')
            ->assertHasErrors(['mobile']);
    }

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload())
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    $this->assertDatabaseCount('schools', 1);
});

it('refuses the fourth successful registration from the same IP within a day', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        Livewire::test(Register::class)
            ->set(validSchoolRegistrationPayload([
                'school_name' => "Rate School {$attempt}",
                'email' => "owner{$attempt}@nalanda.edu.in",
                'mobile' => '987654321'.$attempt,
            ]))
            ->call('register')
            ->assertHasNoErrors();
    }

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload([
            'school_name' => 'Fourth Rate School',
            'email' => 'owner4@nalanda.edu.in',
            'mobile' => '9876543214',
        ]))
        ->call('register')
        ->assertHasErrors(['throttle']);

    $this->assertDatabaseCount('schools', 3);
    $this->assertDatabaseMissing('schools', ['name' => 'Fourth Rate School']);
});

it('blocks the 31st attempt from one IP within an hour even with different emails', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        Livewire::test(Register::class)
            ->set(validSchoolRegistrationPayload([
                'email' => "enum{$attempt}@nalanda.edu.in",
                'mobile' => '12345',
            ]))
            ->call('register')
            ->assertHasErrors(['mobile']);
    }

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload([
            'email' => 'enum31@nalanda.edu.in',
        ]))
        ->call('register')
        ->assertHasErrors(['throttle']);

    $this->assertDatabaseCount('schools', 0);
});

it('clears password fields when registration validation fails', function () {
    $this->seed(RoleSeeder::class);

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload(['email' => 'not-an-email']))
        ->call('register')
        ->assertHasErrors(['email'])
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});

it('turns a concurrent unique-constraint race into an email validation error', function () {
    $this->seed(RoleSeeder::class);

    $exception = (new UniqueConstraintViolationException(
        'sqlite',
        'insert into "users" ("email") values (?)',
        ['aarav@nalanda.edu.in'],
        new PDOException('UNIQUE constraint failed: users.email'),
    ))->setColumns(['email']);

    $this->mock(RegisterSchool::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow($exception);

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload())
        ->call('register')
        ->assertHasErrors(['email' => __('validation.unique', ['attribute' => 'email'])])
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});

it('silently drops honeypot submissions without creating any records', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload(['contact_hp_x9k' => 'https://spam.example.com']))
        ->call('register')
        ->assertNoRedirect();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('schools', 0);
    $this->assertDatabaseCount('memberships', 0);
    $this->assertGuest();
    Notification::assertNothingSent();
});

it('takes a newly registered owner through email verification straight to their school dashboard', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    Livewire::test(Register::class)
        ->set(validSchoolRegistrationPayload([
            'school_name' => 'Saraswati Vidyalaya',
            'email' => 'owner@saraswati.edu.in',
        ]))
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    /** @var User $user */
    $user = User::where('email', 'owner@saraswati.edu.in')->firstOrFail();
    /** @var School $school */
    $school = School::where('name', 'Saraswati Vidyalaya')->firstOrFail();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect('/schools?verified=1');

    $this->actingAs($user)
        ->get('/schools?verified=1')
        ->assertRedirect(route('dashboard', ['school' => $school->id]));

    $this->actingAs($user)
        ->get(route('dashboard', ['school' => $school->id]))
        ->assertOk()
        ->assertSee('Dashboard');
});
