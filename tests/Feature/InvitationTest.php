<?php

use App\Concerns\InvitationValidationRules;
use App\Enums\SchoolRole;
use App\Models\Invitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

test('invitation generates a 26 character ulid id', function () {
    $invitation = Invitation::factory()->create();

    expect($invitation->id)->toBeString()
        ->and(strlen($invitation->id))->toBe(26)
        ->and(Str::isUlid($invitation->id))->toBeTrue();
});

test('validation requires at least one of email or mobile', function () {
    $validatorClass = new class
    {
        use InvitationValidationRules;

        public function rules(): array
        {
            return $this->invitationRules();
        }
    };

    $rules = $validatorClass->rules();

    // Neither email nor mobile
    $vFail = Validator::make([
        'role' => 'Teacher',
    ], $rules);

    expect($vFail->fails())->toBeTrue()
        ->and($vFail->errors()->has('email'))->toBeTrue()
        ->and($vFail->errors()->has('mobile'))->toBeTrue();

    // With email only
    $vEmail = Validator::make([
        'email' => 'teacher@school.org',
        'role' => 'Teacher',
    ], $rules);

    expect($vEmail->passes())->toBeTrue();

    // With mobile only
    $vMobile = Validator::make([
        'mobile' => '+919876543210',
        'role' => 'Teacher',
    ], $rules);

    expect($vMobile->passes())->toBeTrue();
});

test('role validation rejects Owner, superadmin, and an empty role, and accepts Teacher', function () {
    $validatorClass = new class
    {
        use InvitationValidationRules;

        public function rules(): array
        {
            return $this->invitationRules();
        }
    };

    $rules = $validatorClass->rules();

    $vOwner = Validator::make([
        'email' => 'owner@school.org',
        'role' => 'Owner',
    ], $rules);
    expect($vOwner->fails())->toBeTrue()
        ->and($vOwner->errors()->has('role'))->toBeTrue();

    $vSuperadmin = Validator::make([
        'email' => 'superadmin@school.org',
        'role' => 'superadmin',
    ], $rules);
    expect($vSuperadmin->fails())->toBeTrue()
        ->and($vSuperadmin->errors()->has('role'))->toBeTrue();

    $vEmpty = Validator::make([
        'email' => 'empty@school.org',
        'role' => '',
    ], $rules);
    expect($vEmpty->fails())->toBeTrue()
        ->and($vEmpty->errors()->has('role'))->toBeTrue();

    $vTeacher = Validator::make([
        'email' => 'teacher@school.org',
        'role' => 'Teacher',
    ], $rules);
    expect($vTeacher->passes())->toBeTrue();
});

test('an invitation created without expires_at gets an expiry about 7 days from now and counts as pending', function () {
    $school = School::factory()->create();

    $invitation = new Invitation([
        'email' => 'default_expiry@school.org',
        'role' => SchoolRole::Teacher,
    ]);
    $invitation->school_id = $school->id;
    $invitation->token_hash = hash('sha256', Str::random(40));
    $invitation->save();

    expect($invitation->expires_at)->not->toBeNull()
        ->and(now()->diffInDays($invitation->expires_at))->toEqualWithDelta(7, 0.1)
        ->and($invitation->isPending())->toBeTrue();
});

test('the raw token is never stored in plain text and token_hash is hidden', function () {
    $school = School::factory()->create();

    [$invitation, $rawToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'tokens@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    $hashed = hash('sha256', $rawToken);

    // Database record contains the sha256 hash, not the raw token
    expect($invitation->token_hash)->toBe($hashed)
        ->and($invitation->token_hash)->not->toBe($rawToken);

    // token_hash is hidden from array/JSON serialization
    $array = $invitation->toArray();
    expect(array_key_exists('token_hash', $array))->toBeFalse();
});

test('createWithToken and findPendingByToken helpers work as expected', function () {
    $school = School::factory()->create();

    [$invitation, $rawToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'helpers@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    expect($invitation->token_hash)->toBe(hash('sha256', $rawToken))
        ->and($invitation->isPending())->toBeTrue();

    $found = Invitation::findPendingByToken($rawToken);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($invitation->id);

    expect(Invitation::findPendingByToken('invalid-raw-token'))->toBeNull();
});

test('accepting an expired, revoked or already-accepted invitation fails and accepted_at stays null', function () {
    $expired = Invitation::factory()->expired()->create();
    expect(fn () => $expired->accept())->toThrow(LogicException::class)
        ->and($expired->fresh()->accepted_at)->toBeNull();

    $revoked = Invitation::factory()->revoked()->create();
    expect(fn () => $revoked->accept())->toThrow(LogicException::class)
        ->and($revoked->fresh()->accepted_at)->toBeNull();

    $pending = Invitation::factory()->pending()->create();
    $pending->accept();
    expect($pending->fresh()->accepted_at)->not->toBeNull();

    // Already accepted invitation fails second accept
    expect(fn () => $pending->accept())->toThrow(LogicException::class);
});

test('revoking an accepted invitation fails and revoking twice does nothing', function () {
    $accepted = Invitation::factory()->accepted()->create();

    expect(fn () => $accepted->revoke())->toThrow(LogicException::class)
        ->and($accepted->fresh()->revoked_at)->toBeNull();

    $revoked = Invitation::factory()->revoked()->create();
    $revokedAt = $revoked->revoked_at;
    $revoked->revoke();
    expect($revoked->fresh()->revoked_at->eq($revokedAt))->toBeTrue();
});

test('an expired accepted or revoked invitation is not considered pending and cannot be found by token', function () {
    $school = School::factory()->create();

    [$pending, $pendingToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'pending@school.org',
        'role' => SchoolRole::Teacher,
    ]);

    [$expired, $expiredToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'expired@school.org',
        'role' => SchoolRole::Teacher,
        'expires_at' => now()->subDay(),
    ]);

    [$revoked, $revokedToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'revoked@school.org',
        'role' => SchoolRole::Teacher,
    ]);
    $revoked->revoke();

    [$accepted, $acceptedToken] = Invitation::createWithToken([
        'school_id' => $school->id,
        'email' => 'accepted@school.org',
        'role' => SchoolRole::Teacher,
    ]);
    $accepted->accept();

    expect($pending->isPending())->toBeTrue()
        ->and($accepted->isPending())->toBeFalse()
        ->and($revoked->isPending())->toBeFalse()
        ->and($expired->isPending())->toBeFalse();

    // findPendingByToken lookup
    expect(Invitation::findPendingByToken($pendingToken))->not->toBeNull()
        ->and(Invitation::findPendingByToken($expiredToken))->toBeNull()
        ->and(Invitation::findPendingByToken($revokedToken))->toBeNull()
        ->and(Invitation::findPendingByToken($acceptedToken))->toBeNull();
});

test('deleting the inviting user keeps the invitation with invited_by set to null', function () {
    $inviter = User::factory()->create();
    $school = School::factory()->create();

    $invitation = Invitation::factory()->create([
        'school_id' => $school->id,
        'invited_by' => $inviter->id,
    ]);

    expect($invitation->invited_by)->toBe($inviter->id);

    $inviter->delete();

    $refreshed = $invitation->fresh();
    expect($refreshed)->not->toBeNull()
        ->and($refreshed->invited_by)->toBeNull();
});

test('school_id, invited_by, token_hash, accepted_at, accepted_by, last_sent_at, send_count, and revoked_at are not fillable', function () {
    $school = School::factory()->create();

    expect(fn () => new Invitation([
        'school_id' => $school->id,
        'invited_by' => '01ABCDEF',
        'name' => 'Staff Member',
        'email' => 'staff@example.com',
        'role' => SchoolRole::Teacher,
        'token_hash' => 'injected_token',
        'accepted_at' => now(),
        'accepted_by' => '01ABCDEF',
        'last_sent_at' => now(),
        'send_count' => 99,
        'revoked_at' => now(),
    ]))->toThrow(MassAssignmentException::class);

    foreach (['accepted_by', 'last_sent_at', 'send_count'] as $protectedField) {
        expect(fn () => new Invitation([
            'name' => 'Staff Member',
            'email' => 'staff@example.com',
            'role' => SchoolRole::Teacher,
            $protectedField => $protectedField === 'send_count' ? 5 : '01ABCDEF',
        ]))->toThrow(MassAssignmentException::class);
    }

    $invitation = new Invitation([
        'name' => '  Priya Sharma  ',
        'email' => 'staff@example.com',
        'role' => SchoolRole::Teacher,
        'expires_at' => now()->addDays(3),
    ]);
    $invitation->school_id = $school->id;
    $invitation->token_hash = hash('sha256', 'valid_token');
    $invitation->save();

    expect($invitation->fresh()->name)->toBe('Priya Sharma')
        ->and($invitation->fresh()->token_hash)->toBe(hash('sha256', 'valid_token'))
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and($invitation->fresh()->accepted_by)->toBeNull()
        ->and($invitation->fresh()->send_count)->toBe(1)
        ->and($invitation->fresh()->last_sent_at)->not->toBeNull()
        ->and($invitation->fresh()->revoked_at)->toBeNull();
});

test('mobile number is normalized via NormalizedMobile cast on invitation', function () {
    $invitation = Invitation::factory()->create([
        'email' => null,
        'mobile' => '9876543210',
    ]);

    expect($invitation->mobile)->toBe('+919876543210');

    $invitation->mobile = '   ';
    $invitation->save();

    expect($invitation->fresh()->mobile)->toBeNull();
});

test('factory states for pending, accepted, revoked, expired, forEmail, and forMobile work properly', function () {
    $forEmail = Invitation::factory()->forEmail('custom@example.com')->create();
    expect($forEmail->email)->toBe('custom@example.com')
        ->and($forEmail->mobile)->toBeNull();

    $forMobile = Invitation::factory()->forMobile('+919876543210')->create();
    expect($forMobile->mobile)->toBe('+919876543210')
        ->and($forMobile->email)->toBeNull();

    $accepted = Invitation::factory()->accepted()->create();
    expect($accepted->accepted_at)->not->toBeNull();

    $revoked = Invitation::factory()->revoked()->create();
    expect($revoked->revoked_at)->not->toBeNull();

    $expired = Invitation::factory()->expired()->create();
    expect($expired->expires_at->isPast())->toBeTrue();
});

test('school_id cannot be changed once an invitation is created', function () {
    $invitation = Invitation::factory()->create();
    $otherSchool = School::factory()->create();

    $invitation->school_id = $otherSchool->id;

    expect(fn () => $invitation->save())->toThrow(LogicException::class, 'The school_id cannot be changed once a record is created.');
});

test('open_flag enforces at most one open invitation per email per school while allowing other schools, closed history, and mobile invites', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    [$openA] = Invitation::createWithToken([
        'school_id' => $schoolA->id,
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
        'role' => SchoolRole::Teacher,
    ]);

    expect($openA->fresh()->open_flag)->toBe(1);

    // Duplicate open invitation in the same school for the same email is rejected by DB unique index.
    expect(fn () => Invitation::createWithToken([
        'school_id' => $schoolA->id,
        'name' => 'Asha Duplicate',
        'email' => 'asha@example.com',
        'role' => SchoolRole::Accountant,
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Same email can have an open invitation in another school.
    [$openB] = Invitation::createWithToken([
        'school_id' => $schoolB->id,
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
        'role' => SchoolRole::Teacher,
    ]);
    expect($openB->fresh()->open_flag)->toBe(1);

    // Revoking clears open_flag so a fresh invitation for the same school + email is permitted.
    $openA->revoke();
    expect($openA->fresh()->open_flag)->toBeNull();

    [$reinvitedA] = Invitation::createWithToken([
        'school_id' => $schoolA->id,
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
        'role' => SchoolRole::Teacher,
    ]);
    expect($reinvitedA->fresh()->open_flag)->toBe(1);

    // Accepting clears open_flag and records accepted_by.
    $acceptor = User::factory()->create(['email' => 'asha@example.com']);
    $reinvitedA->accept($acceptor);
    expect($reinvitedA->fresh()->open_flag)->toBeNull()
        ->and($reinvitedA->fresh()->accepted_by)->toBe($acceptor->id);

    // After acceptance, a new invitation for the same email in the same school is allowed at DB level.
    [$thirdA] = Invitation::createWithToken([
        'school_id' => $schoolA->id,
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
        'role' => SchoolRole::Accountant,
    ]);
    expect($thirdA->fresh()->open_flag)->toBe(1);

    // Multiple open mobile-only invitations (email = null) in the same school are allowed.
    $mobileOne = Invitation::factory()->for($schoolA)->forMobile('+919876543211')->create();
    $mobileTwo = Invitation::factory()->for($schoolA)->forMobile('+919876543212')->create();
    expect($mobileOne->fresh()->open_flag)->toBe(1)
        ->and($mobileTwo->fresh()->open_flag)->toBe(1);
});

test('activitylog globally excludes token_hash', function () {
    expect(config('activitylog.default_except_attributes'))->toContain('token_hash');
});
