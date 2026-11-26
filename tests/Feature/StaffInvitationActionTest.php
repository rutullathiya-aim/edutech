<?php

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResendInvitation;
use App\Actions\Staff\RevokeInvitation;
use App\Enums\SchoolRole;
use App\Models\Activity;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Create an active member with the given role inside the specified school.
 */
function setupStaffInviteActor(School $school, SchoolRole $role, ?User $user = null): User
{
    test()->seed(PermissionSeeder::class);

    $actor = $user ?? User::factory()->create();
    Membership::factory()->active()->for($school)->for($actor)->create();

    setPermissionsTeamId($school->id);
    $actor->unsetRelation('roles', 'permissions');
    $actor->assignRole($role->value);
    $actor->unsetRelation('roles', 'permissions');

    CurrentSchool::set($school);

    return $actor;
}

it('allows Admin to invite Teacher or Accountant and refuses Admin or Owner role escalation', function () {
    Notification::fake();

    $school = School::factory()->active()->create();
    $admin = setupStaffInviteActor($school, SchoolRole::Admin);
    $action = app(InviteStaff::class);

    $teacherInvite = $action->handle($admin, '  Kavita Nair  ', 'Kavita@School.IN ', SchoolRole::Teacher);
    $accountantInvite = $action->handle($admin, 'Ramesh Verma', 'ramesh@school.in', SchoolRole::Accountant);

    expect($teacherInvite->name)->toBe('Kavita Nair')
        ->and($teacherInvite->email)->toBe('kavita@school.in')
        ->and($teacherInvite->role)->toBe(SchoolRole::Teacher)
        ->and($teacherInvite->invited_by)->toBe($admin->id)
        ->and($teacherInvite->send_count)->toBe(1)
        ->and($teacherInvite->last_sent_at)->not->toBeNull()
        ->and($accountantInvite->role)->toBe(SchoolRole::Accountant);

    Notification::assertCount(2);

    expect(fn () => $action->handle($admin, 'Another Admin', 'admin2@school.in', SchoolRole::Admin))
        ->toThrow(ValidationException::class);

    expect(fn () => $action->handle($admin, 'Fake Owner', 'owner2@school.in', SchoolRole::Owner))
        ->toThrow(ValidationException::class);

    expect(Invitation::count())->toBe(2);
});

it('allows Owner to invite Admin and refuses inviting Owner', function () {
    Notification::fake();

    $school = School::factory()->active()->create();
    $owner = setupStaffInviteActor($school, SchoolRole::Owner);
    $action = app(InviteStaff::class);

    $adminInvite = $action->handle($owner, 'Suresh Kulkarni', 'suresh@school.in', SchoolRole::Admin);

    expect($adminInvite->role)->toBe(SchoolRole::Admin);

    expect(fn () => $action->handle($owner, 'Second Owner', 'owner2@school.in', SchoolRole::Owner))
        ->toThrow(ValidationException::class);
});

it('forbids non-managing roles from creating, resending, or revoking invitations', function (SchoolRole $role) {
    Notification::fake();

    $school = School::factory()->active()->create();
    $owner = setupStaffInviteActor($school, SchoolRole::Owner);
    $invitation = app(InviteStaff::class)->handle($owner, 'Pending Teacher', 'pending@school.in', SchoolRole::Teacher);

    $nonManager = setupStaffInviteActor($school, $role);

    expect(fn () => app(InviteStaff::class)->handle($nonManager, 'Blocked', 'blocked@school.in', SchoolRole::Teacher))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(ResendInvitation::class)->handle($nonManager, $invitation))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(RevokeInvitation::class)->handle($nonManager, $invitation))
        ->toThrow(AuthorizationException::class);
})->with([
    'Teacher' => [SchoolRole::Teacher],
    'Accountant' => [SchoolRole::Accountant],
    'Parent' => [SchoolRole::Parent],
]);

it('rejects active members, disabled members, and duplicate open invites with clear messages, while revoking stale expired invites', function () {
    Notification::fake();

    $school = School::factory()->active()->create();
    $owner = setupStaffInviteActor($school, SchoolRole::Owner);
    $action = app(InviteStaff::class);

    $activeUser = User::factory()->create(['email' => 'active@school.in']);
    Membership::factory()->active()->for($school)->for($activeUser)->create();

    $disabledUser = User::factory()->create(['email' => 'disabled@school.in']);
    Membership::factory()->disabled()->for($school)->for($disabledUser)->create();

    try {
        $action->handle($owner, 'Active Member', 'active@school.in', SchoolRole::Teacher);
        test()->fail('Expected ValidationException for active member.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'])->toContain('Already on your staff');
    }

    try {
        $action->handle($owner, 'Disabled Member', 'disabled@school.in', SchoolRole::Teacher);
        test()->fail('Expected ValidationException for disabled member.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'])->toContain('This person was disabled. Re-enable them instead.');
    }

    $action->handle($owner, 'Open Invite', 'open@school.in', SchoolRole::Teacher);

    try {
        $action->handle($owner, 'Open Invite Again', 'open@school.in', SchoolRole::Teacher);
        test()->fail('Expected ValidationException for existing open invite.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'])->toContain('Already invited. Resend?');
    }

    // Expired open invite is automatically revoked inside transaction so re-inviting succeeds.
    $expired = Invitation::factory()->for($school)->expired()->create([
        'name' => 'Expired Person',
        'email' => 'expired@school.in',
        'role' => SchoolRole::Teacher,
    ]);

    $fresh = $action->handle($owner, 'Expired Person', 'expired@school.in', SchoolRole::Teacher);

    expect($expired->fresh()->revoked_at)->not->toBeNull()
        ->and($fresh->id)->not->toBe($expired->id)
        ->and($fresh->isPending())->toBeTrue();
});

it('hashes tokens, never leaks tokens in activity_log, and omits recipient email from notification body', function () {
    Notification::fake();

    $school = School::factory()->active()->create(['name' => 'Nalanda Public School']);
    $owner = setupStaffInviteActor($school, SchoolRole::Owner, User::factory()->create(['name' => 'Aarav Mehta']));

    $invitation = app(InviteStaff::class)->handle($owner, 'Meera Joshi', 'meera.secret@gmail.com', SchoolRole::Teacher);

    $sentRawToken = null;
    Notification::assertSentOnDemand(
        StaffInvitationNotification::class,
        function (StaffInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$sentRawToken): bool {
            expect($notification)->toBeInstanceOf(ShouldQueue::class)
                ->and($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
                ->and($channels)->toBe(['mail'])
                ->and($notifiable->routes['mail'] ?? null)->toBe('meera.secret@gmail.com');

            $sentRawToken = $notification->rawToken;
            $mail = $notification->toMail($notifiable);
            $rendered = implode(' ', [...$mail->introLines, (string) $mail->actionUrl, (string) $mail->subject]);

            expect($mail->subject)->toContain('Nalanda Public School')
                ->and($rendered)->toContain('Aarav Mehta')
                ->and($rendered)->toContain('Nalanda Public School')
                ->and($rendered)->toContain('Teacher')
                ->and($mail->actionUrl)->toContain('/invitations/'.$sentRawToken)
                ->and($rendered)->not->toContain('meera.secret@gmail.com');

            return true;
        },
    );

    expect($sentRawToken)->toBeString()
        ->and(strlen($sentRawToken))->toBe(40)
        ->and($invitation->token_hash)->toBe(hash('sha256', $sentRawToken))
        ->and($invitation->token_hash)->not->toBe($sentRawToken);

    $audit = Activity::where('event', 'invitation_created')
        ->where('subject_id', $invitation->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->school_id)->toBe($school->id)
        ->and($audit->causer_id)->toBe($owner->id)
        ->and($audit->properties->get('email'))->toBe('me***@gmail.com')
        ->and($audit->properties->get('role'))->toBe('Teacher');

    $serializedAudits = json_encode(Activity::all()->toArray());
    expect($serializedAudits)->not->toContain($sentRawToken)
        ->and($serializedAudits)->not->toContain($invitation->token_hash)
        ->and($serializedAudits)->not->toContain('meera.secret@gmail.com');
});

it('sends no invitation email if the database transaction rolls back', function () {
    Notification::fake();

    $school = School::factory()->active()->create();
    $owner = setupStaffInviteActor($school, SchoolRole::Owner);

    $shouldFailAudit = true;

    Activity::creating(function () use (&$shouldFailAudit): bool {
        if ($shouldFailAudit) {
            throw new RuntimeException('Simulated audit write failure');
        }

        return true;
    });

    try {
        expect(fn () => app(InviteStaff::class)->handle($owner, 'Rollback Test', 'rollback@school.in', SchoolRole::Teacher))
            ->toThrow(RuntimeException::class);

        expect(Invitation::count())->toBe(0);
        Notification::assertNothingSent();
    } finally {
        $shouldFailAudit = false;
    }
});

it('queues invitation emails encrypted onto the database queue without leaking tokens or emails', function () {
    config(['queue.default' => 'database']);

    $school = School::factory()->active()->create();
    $owner = setupStaffInviteActor($school, SchoolRole::Owner);

    $invitation = app(InviteStaff::class)->handle($owner, 'Encrypted Recipient', 'secret_invitee@school.in', SchoolRole::Teacher);

    $job = DB::table('jobs')->latest('id')->first();
    expect($job)->not->toBeNull()
        ->and((string) $job->payload)->not->toContain('secret_invitee@school.in')
        ->and((string) $job->payload)->not->toContain('Encrypted Recipient')
        ->and((string) $job->payload)->not->toContain($invitation->token_hash);
});

it('enforces the 50/day school limit, resend cooldown, max 5 sends, token rotation, and revoke audit', function () {
    Notification::fake();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 12:00:00', 'Asia/Kolkata'));

    try {
        $school = School::factory()->active()->create();
        $otherSchool = School::factory()->active()->create();
        $owner = setupStaffInviteActor($school, SchoolRole::Owner);
        $inviteAction = app(InviteStaff::class);
        $resendAction = app(ResendInvitation::class);
        $revokeAction = app(RevokeInvitation::class);

        Invitation::factory()->count(49)->for($school)->create([
            'created_at' => now(),
        ]);

        // 50th invite succeeds.
        $target = $inviteAction->handle($owner, 'Fiftieth Invite', 'fifty@school.in', SchoolRole::Admin);
        $firstHash = $target->token_hash;

        // 51st invite on the same day for this school is refused.
        try {
            $inviteAction->handle($owner, 'Fifty First', 'fiftyone@school.in', SchoolRole::Teacher);
            test()->fail('Expected daily limit ValidationException.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('email');
        }

        // Another school still has its own daily allowance.
        $otherOwner = setupStaffInviteActor($otherSchool, SchoolRole::Owner);
        $otherInvite = $inviteAction->handle($otherOwner, 'Other School Staff', 'other@school.in', SchoolRole::Teacher);
        expect($otherInvite->school_id)->toBe($otherSchool->id);

        // Switch back to first school.
        CurrentSchool::set($school);
        setPermissionsTeamId($school->id);
        $owner->unsetRelation('roles', 'permissions');

        // Resending within the 1-minute cooldown is refused.
        CarbonImmutable::setTestNow(now()->addSeconds(30));
        expect(fn () => $resendAction->handle($owner, $target))
            ->toThrow(ValidationException::class);

        // After cooldown, resend succeeds, rotates token hash, kills previous token, and extends expiry.
        CarbonImmutable::setTestNow(now()->addSeconds(61));
        $previousExpiry = $target->expires_at;
        $resent = $resendAction->handle($owner, $target);

        expect($resent->token_hash)->not->toBe($firstHash)
            ->and($resent->send_count)->toBe(2)
            ->and($resent->expires_at->gt($previousExpiry))->toBeTrue();

        $resentRawToken = null;
        Notification::assertSentOnDemand(
            StaffInvitationNotification::class,
            function (StaffInvitationNotification $notification) use (&$resentRawToken, $resent): bool {
                if (Invitation::hashToken($notification->rawToken) === $resent->token_hash) {
                    $resentRawToken = $notification->rawToken;

                    return true;
                }

                return false;
            },
        );

        expect($resentRawToken)->not->toBeNull()
            ->and(Invitation::findPendingByToken($resentRawToken)?->id)->toBe($target->id);

        // Resending up to 5 total sends works; 6th send is blocked.
        for ($attempt = 3; $attempt <= 5; $attempt++) {
            CarbonImmutable::setTestNow(now()->addSeconds(61));
            $resent = $resendAction->handle($owner, $resent);
            expect($resent->send_count)->toBe($attempt);
        }

        CarbonImmutable::setTestNow(now()->addSeconds(61));
        expect(fn () => $resendAction->handle($owner, $resent))
            ->toThrow(ValidationException::class);

        // Admin cannot resend or revoke an Admin invite or another school's invite.
        $admin = setupStaffInviteActor($school, SchoolRole::Admin);
        expect(fn () => $resendAction->handle($admin, $resent))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $revokeAction->handle($admin, $resent))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $resendAction->handle($admin, $otherInvite))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $revokeAction->handle($admin, $otherInvite))
            ->toThrow(ModelNotFoundException::class);
        expect($otherInvite->fresh()->revoked_at)->toBeNull();

        // Owner revokes successfully and logs audit entry without any token.
        $revoked = $revokeAction->handle($owner, $resent);
        expect($revoked->isRevoked())->toBeTrue()
            ->and($revoked->fresh()?->open_flag)->toBeNull()
            ->and(Invitation::findPendingByToken($resentRawToken))->toBeNull();

        $revokeAudit = Activity::where('event', 'invitation_revoked')
            ->where('subject_id', $revoked->id)
            ->first();
        expect($revokeAudit)->not->toBeNull()
            ->and($revokeAudit->properties->get('email'))->toBe('fi***@school.in');

        $allAuditsJson = json_encode(Activity::all()->toArray());
        expect($allAuditsJson)->not->toContain($resentRawToken)
            ->and($allAuditsJson)->not->toContain($firstHash)
            ->and($allAuditsJson)->not->toContain($resent->token_hash);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
