<?php

use App\Concerns\SchoolValidationRules;
use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use App\Enums\SchoolStatus;
use App\Models\School;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

test('factory creates a school with a 26 character ulid id', function () {
    $school = School::factory()->create();

    expect($school->id)->toBeString()
        ->and(strlen($school->id))->toBe(26)
        ->and(Str::isUlid($school->id))->toBeTrue();
});

test('status, board, and state come back as enums', function () {
    $school = School::factory()->create([
        'status' => SchoolStatus::Active,
        'board' => SchoolBoard::Cbse,
        'state' => IndianState::Gujarat,
    ]);

    expect($school->status)->toBe(SchoolStatus::Active)
        ->and($school->board)->toBe(SchoolBoard::Cbse)
        ->and($school->state)->toBe(IndianState::Gujarat);
});

test('platform and billing fields cannot be mass assigned', function () {
    $future = now()->addYear();

    expect(fn () => School::create([
        'name' => 'Demo Public School',
        'status' => SchoolStatus::Active->value,
        'trial_ends_at' => $future,
        'student_limit' => 500,
        'logo_path' => 'malicious/path.png',
        'settings' => ['malicious' => 'data'],
    ]))->toThrow(MassAssignmentException::class);

    $school = School::create([
        'name' => 'Demo Public School',
    ]);

    expect($school->status)->toBe(SchoolStatus::Trial)
        ->and($school->trial_ends_at)->toBeNull()
        ->and($school->student_limit)->toBeNull()
        ->and($school->logo_path)->toBeNull()
        ->and($school->settings)->toBeNull();

    // Can be set explicitly in code
    $school->status = SchoolStatus::Active;
    $school->trial_ends_at = $future;
    $school->student_limit = 500;
    $school->save();

    $refreshed = $school->fresh();
    expect($refreshed->status)->toBe(SchoolStatus::Active)
        ->and($refreshed->student_limit)->toBe(500);
});

test('validation rejects bad pincode, bad udise code, and invalid state', function () {
    $validatorClass = new class
    {
        use SchoolValidationRules;

        public function rules(): array
        {
            return $this->schoolRules();
        }
    };

    $rules = $validatorClass->rules();

    // Bad pincodes: 5 digits, leading 0
    foreach (['12345', '012345', 'abcdef', '1234567'] as $badPin) {
        $v = Validator::make(['pincode' => $badPin], ['pincode' => $rules['pincode']]);
        expect($v->fails())->toBeTrue()
            ->and($v->errors()->has('pincode'))->toBeTrue();
    }

    // Valid pincode: 6 digits, first digit 1-9
    $validPin = Validator::make(['pincode' => '380015'], ['pincode' => $rules['pincode']]);
    expect($validPin->passes())->toBeTrue();

    // Bad UDISE codes: letters, 10 digits, 12 digits
    foreach (['ABC12345678', '1234567890', '123456789012'] as $badUdise) {
        $v = Validator::make(['udise_code' => $badUdise], ['udise_code' => $rules['udise_code']]);
        expect($v->fails())->toBeTrue()
            ->and($v->errors()->has('udise_code'))->toBeTrue();
    }

    // Valid UDISE code: exactly 11 digits
    $validUdise = Validator::make(['udise_code' => '24070100101'], ['udise_code' => $rules['udise_code']]);
    expect($validUdise->passes())->toBeTrue();

    // Invalid state
    $invalidState = Validator::make(['state' => 'California'], ['state' => $rules['state']]);
    expect($invalidState->fails())->toBeTrue()
        ->and($invalidState->errors()->has('state'))->toBeTrue();

    // Valid state
    $validState = Validator::make(['state' => 'Gujarat'], ['state' => $rules['state']]);
    expect($validState->passes())->toBeTrue();
});

test('blank email is stored as null', function () {
    $school = new School([
        'name' => 'Email School',
        'email' => '   ',
    ]);

    expect($school->email)->toBeNull();

    $schoolWithEmail = new School([
        'name' => 'Real School',
        'email' => '  OFFICE@School.org  ',
    ]);

    expect($schoolWithEmail->email)->toBe('office@school.org');
});

test('soft deleting a school excludes it from normal queries', function () {
    $school = School::factory()->create();

    expect(School::find($school->id))->not->toBeNull();

    $school->delete();

    expect(School::find($school->id))->toBeNull()
        ->and(School::withTrashed()->find($school->id))->not->toBeNull()
        ->and($school->trashed())->toBeTrue();
});

test('factory states for active, suspended, cancelled, and expired trial work correctly', function () {
    $active = School::factory()->active()->create();
    expect($active->status)->toBe(SchoolStatus::Active);

    $suspended = School::factory()->suspended()->create();
    expect($suspended->status)->toBe(SchoolStatus::Suspended);

    $cancelled = School::factory()->cancelled()->create();
    expect($cancelled->status)->toBe(SchoolStatus::Cancelled);

    $expired = School::factory()->expiredTrial()->create();
    expect($expired->status)->toBe(SchoolStatus::Trial)
        ->and($expired->trial_ends_at->isPast())->toBeTrue();
});
