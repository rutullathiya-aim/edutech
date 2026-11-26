<?php

use App\Actions\Staff\AcceptInvitation;
use App\Actions\Staff\InviteStaff;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Exceptions\InvalidInvitationException;
use App\Livewire\Invitations\Accept as AcceptInvitationPage;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Create an active school + owner + queued invite and return the invitation and raw token.
 *
 * @return array{school: School, owner: User, invitation: Invitation, rawToken: string}
 */
function createPendingStaffInvite(
    string $email = 'meera.joshi@gmail.com',
    string $name = 'Meera Joshi',
    SchoolRole $role = SchoolRole::Teacher,
    ?School $school = null,
): array {
    test()->seed(PermissionSeeder::class);
    Notification::fake();

    $school ??= School::factory()->active()->create(['name' => 'Nalanda Public School']);
    $owner = User::factory()->create(['name' => 'Aarav Mehta']);
    Membership::factory()->active()->for($school)->for($owner)->create();

    setPermissionsTeamId($school->id);
    $owner->unsetRelation('roles', 'permissions');
    $owner->assignRole(SchoolRole::Owner->value);
    $owner->unsetRelation('roles', 'permissions');

    CurrentSchool::set($school);

    try {
        $invitation = app(InviteStaff::class)->handle($owner, $name, $email, $role);
    } finally {
        CurrentSchool::forget();
        setPermissionsTeamId(null);
    }

    $rawToken = null;
    Notification::assertSentOnDemand(
        StaffInvitationNotification::class,
        function (StaffInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$rawToken, $invitation): bool {
            if (Invitation::hashToken($notification->rawToken) === $invitation->token_hash) {
                $rawToken = $notification->rawToken;

                return true;
            }

            return false;
        },
    );

    expect($rawToken)->toBeString();

    return [
        'school' => $school,
        'owner' => $owner,
        'invitation' => $invitation,
        'rawToken' => (string) $rawToken,
    ];
}

it('handles Case A: creates a verified user using the invitation email only, grants role in that school only, logs in, and regenerates session', function () {
    $otherSchool = School::factory()->active()->create();
    ['school' => $school, 'invitation' => $invitation, 'rawToken' => $rawToken] = createPendingStaffInvite(
        email: 'ravi.sharma@gmail.com',
        name: 'Ravi Sharma',
        role: SchoolRole::Teacher,
    );

    $tokenResponse = $this->get(route('invitations.show', ['token' => $rawToken]));
    $tokenResponse->assertRedirect(route('invitations.accept'))
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $acceptPage = $this->get(route('invitations.accept'));
    $acceptPage->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('Join Nalanda Public School as Teacher')
        ->assertSee('ra***@gmail.com')
        ->assertDontSee('ravi.sharma@gmail.com');

    // Neither raw token nor token hash leaks into the rendered page / Livewire snapshot.
    expect($acceptPage->getContent())->not->toContain($rawToken)
        ->and($acceptPage->getContent())->not->toContain($invitation->token_hash);

    $previousSessionId = session()->getId();

    $component = Livewire::test(AcceptInvitationPage::class)
        ->assertSet('name', 'Ravi Sharma')
        ->set('name', 'Ravi K. Sharma')
        ->set('password', 'StrongPass#2026')
        ->set('password_confirmation', 'StrongPass#2026')
        ->set('terms', true);

    // Simulate tampered snapshot/payload attempting to override email via Livewire update.
    expect(fn () => $component->set('email', 'attacker@evil.com'))
        ->not->toThrow(Throwable::class);

    $component->call('registerAndAccept')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', ['school' => $school->id]));

    expect(User::where('email', 'attacker@evil.com')->exists())->toBeFalse();

    $newUser = User::where('email', 'ravi.sharma@gmail.com')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('Ravi K. Sharma')
        ->and($newUser->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($newUser);
    expect(session()->getId())->not->toBe($previousSessionId)
        ->and(session()->has('pending_invitation_token'))->toBeFalse();

    $membership = Membership::where('school_id', $school->id)
        ->where('user_id', $newUser->id)
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($newUser->staffRoleForSchool($school->id))->toBe(SchoolRole::Teacher)
        ->and($newUser->staffRoleForSchool($otherSchool->id))->toBeNull();

    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by)->toBe($newUser->id)
        ->and($invitation->fresh()->open_flag)->toBeNull();

    $audit = Activity::where('event', 'invitation_accepted')
        ->where('subject_id', $invitation->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->school_id)->toBe($school->id)
        ->and($audit->causer_id)->toBe($newUser->id)
        ->and($audit->properties->get('terms_version'))->not->toBeNull()
        ->and($audit->properties->get('terms_accepted_at'))->not->toBeNull()
        ->and($audit->properties->get('email'))->toBe('ra***@gmail.com');

    $allAuditsJson = json_encode(Activity::all()->toArray());
    expect($allAuditsJson)->not->toContain($rawToken)
        ->and($allAuditsJson)->not->toContain($invitation->token_hash);
});

it('handles Case B: forces guests with an existing account to log in and redirects back to accept without creating a second account', function () {
    $existingUser = User::factory()->create([
        'name' => 'Existing Teacher',
        'email' => 'existing.teacher@gmail.com',
        'password' => 'KnownPass#2026',
    ]);

    ['school' => $school, 'invitation' => $invitation, 'rawToken' => $rawToken] = createPendingStaffInvite(
        email: 'existing.teacher@gmail.com',
        name: 'Different Draft Name',
        role: SchoolRole::Accountant,
    );

    $this->get(route('invitations.show', ['token' => $rawToken]))
        ->assertRedirect(route('invitations.accept'));

    $this->get(route('invitations.accept'))
        ->assertOk()
        ->assertSee('You already have an account. Log in to accept.')
        ->assertSee('ex***@gmail.com')
        ->assertSee(route('login'), false)
        ->assertDontSee('data-test="accept-create-account-form"', false);

    // Forged call to registerAndAccept creates no second user and no membership.
    Livewire::test(AcceptInvitationPage::class)
        ->set('name', 'Fake')
        ->set('password', 'AnotherPass#123')
        ->set('password_confirmation', 'AnotherPass#123')
        ->set('terms', true)
        ->call('registerAndAccept')
        ->assertHasErrors('invitation');

    expect(User::where('email', 'existing.teacher@gmail.com')->count())->toBe(1)
        ->and(Membership::where('school_id', $school->id)->where('user_id', $existingUser->id)->exists())->toBeFalse();

    // Logging in redirects cleanly via url.intended back to /invitations/accept as Case C.
    $this->post(route('login.store'), [
        'email' => 'existing.teacher@gmail.com',
        'password' => 'KnownPass#2026',
    ])->assertRedirect(route('invitations.accept'));

    $this->get(route('invitations.accept'))
        ->assertOk()
        ->assertSee('Join Nalanda Public School as Accountant')
        ->assertSee('Accept invitation');

    Livewire::test(AcceptInvitationPage::class)
        ->call('acceptExisting')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', ['school' => $school->id]));

    expect(User::where('email', 'existing.teacher@gmail.com')->count())->toBe(1)
        ->and($existingUser->staffRoleForSchool($school->id))->toBe(SchoolRole::Accountant)
        ->and($invitation->fresh()->accepted_by)->toBe($existingUser->id);
});

it('handles Case D: refuses acceptance when logged in with a different email and leaves memberships and roles unchanged', function () {
    $wrongUser = User::factory()->create(['email' => 'wrong.person@gmail.com']);

    ['school' => $school, 'invitation' => $invitation, 'rawToken' => $rawToken] = createPendingStaffInvite(
        email: 'ravi@gmail.com',
        role: SchoolRole::Teacher,
    );

    $this->actingAs($wrongUser)
        ->get(route('invitations.show', ['token' => $rawToken]))
        ->assertRedirect(route('invitations.accept'));

    $this->actingAs($wrongUser)
        ->get(route('invitations.accept'))
        ->assertOk()
        ->assertSee('This invitation was sent to ra***@gmail.com. Log out and sign in with that account.')
        ->assertSee('Log out')
        ->assertDontSee('data-test="accept-invitation-button"', false)
        ->assertDontSee('data-test="accept-create-account-form"', false);

    Livewire::actingAs($wrongUser)
        ->test(AcceptInvitationPage::class)
        ->call('acceptExisting')
        ->assertHasErrors('invitation');

    expect(fn () => app(AcceptInvitation::class)->handle($rawToken, $wrongUser))
        ->toThrow(ValidationException::class);

    expect(Membership::where('school_id', $school->id)->where('user_id', $wrongUser->id)->exists())->toBeFalse()
        ->and($wrongUser->staffRoleForSchool($school->id))->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('shows the same generic invalid page for expired, revoked, accepted, and wrong tokens, and GET never accepts', function () {
    $school = School::factory()->active()->create();
    $this->seed(PermissionSeeder::class);

    // Expired
    [$expiredInvite, $expiredToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'name' => 'Expired',
        'email' => 'expired@school.in',
        'role' => SchoolRole::Teacher,
        'expires_at' => now()->subMinute(),
    ]);

    // Revoked
    [$revokedInvite, $revokedToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'name' => 'Revoked',
        'email' => 'revoked@school.in',
        'role' => SchoolRole::Teacher,
    ]);
    $revokedInvite->revoke();

    // Accepted
    [$acceptedInvite, $acceptedToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'name' => 'Accepted',
        'email' => 'accepted@school.in',
        'role' => SchoolRole::Teacher,
    ]);
    $acceptedInvite->accept();

    $wrongToken = Str::random(40);

    $bodies = [];

    foreach ([$expiredToken, $revokedToken, $acceptedToken, $wrongToken] as $badToken) {
        $response = $this->get(route('invitations.show', ['token' => $badToken]));
        $response->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('data-test="invalid-invitation"', false)
            ->assertSee('This invitation is no longer valid. Ask your school to send a new one.')
            ->assertDontSee($school->name)
            ->assertDontSee('school.in');

        $strippedBody = preg_replace(
            '/(<head\b.*?<\/head>|<script\b.*?<\/script>|<!--\s*Livewire Scripts\s*-->)/si',
            '',
            $response->getContent(),
        );
        $bodies[] = trim((string) preg_replace('/\s+/', ' ', (string) $strippedBody));
    }

    expect($bodies[0])->not->toBe('')
        ->and($bodies[0])->toBe($bodies[1])
        ->and($bodies[1])->toBe($bodies[2])
        ->and($bodies[2])->toBe($bodies[3]);

    // GET on a valid token with matching logged-in user (Case C) NEVER accepts without POST.
    $invitedUser = User::factory()->create(['email' => 'linkscanner@school.in']);
    ['invitation' => $validInvite, 'rawToken' => $validToken] = createPendingStaffInvite(
        email: 'linkscanner@school.in',
        role: SchoolRole::Teacher,
        school: $school,
    );

    $membershipCountBefore = Membership::count();

    $this->actingAs($invitedUser)
        ->get(route('invitations.show', ['token' => $validToken]))
        ->assertRedirect(route('invitations.accept'));

    $this->actingAs($invitedUser)
        ->get(route('invitations.accept'))
        ->assertOk()
        ->assertSee('Accept invitation');

    expect(Membership::count())->toBe($membershipCountBefore)
        ->and($validInvite->fresh()->accepted_at)->toBeNull();
});

it('creates exactly one membership when accepting twice', function () {
    $user = User::factory()->create(['email' => 'double@school.in']);

    ['school' => $school, 'invitation' => $invitation, 'rawToken' => $rawToken] = createPendingStaffInvite(
        email: 'double@school.in',
        role: SchoolRole::Teacher,
    );

    $action = app(AcceptInvitation::class);

    $result = $action->handle($rawToken, $user);
    expect($result['membership'])->toBeInstanceOf(Membership::class);

    expect(fn () => $action->handle($rawToken, $user))
        ->toThrow(InvalidInvitationException::class, 'This invitation is no longer valid. Ask your school to send a new one.');

    // Submitting a stale Livewire screen falls back cleanly to generic invalid state without throwing.
    $this->withSession(['pending_invitation_token' => $rawToken]);

    Livewire::actingAs($user)
        ->test(AcceptInvitationPage::class)
        ->call('acceptExisting')
        ->assertSee('This invitation is no longer valid. Ask your school to send a new one.');

    expect(Membership::where('school_id', $school->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('removes the token from the redirect URL, enforces Referrer-Policy: no-referrer, and throttles token lookups at 10 per minute per IP', function () {
    ['rawToken' => $rawToken] = createPendingStaffInvite(
        email: 'ratelimit@school.in',
        role: SchoolRole::Teacher,
    );

    $redirectResponse = $this->get(route('invitations.show', ['token' => $rawToken]));
    $redirectLocation = (string) $redirectResponse->headers->get('Location');

    $redirectResponse->assertRedirect(route('invitations.accept'))
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($redirectLocation)->not->toContain($rawToken);

    // 9 more requests reach the limit of 10/minute; 11th request is throttled with 429.
    for ($attempt = 2; $attempt <= 10; $attempt++) {
        $this->get(route('invitations.show', ['token' => $rawToken]))
            ->assertRedirect(route('invitations.accept'));
    }

    $this->get(route('invitations.show', ['token' => $rawToken]))
        ->assertTooManyRequests();
});
