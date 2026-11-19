<?php

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\Settings\Profile;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('user generates a 26 character ulid id', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeString()
        ->and(strlen($user->id))->toBe(26)
        ->and(Str::isUlid($user->id))->toBeTrue();
});

test('user can be created with only email and null mobile', function () {
    $user = User::create([
        'name' => 'Email User',
        'email' => 'parent1@example.com',
        'password' => 'password123',
    ]);

    expect($user->email)->toBe('parent1@example.com')
        ->and($user->mobile)->toBeNull()
        ->and($user->is_platform_admin)->toBeFalse();
});

test('user can be created with only mobile and null email and null password', function () {
    $user = User::create([
        'name' => 'Mobile Parent',
        'mobile' => '9876543210',
    ]);

    expect($user->mobile)->toBe('+919876543210')
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull();
});

test('multiple users can have null email without unique constraint violation', function () {
    $user1 = User::create([
        'name' => 'Parent 1',
        'mobile' => '9876543210',
    ]);

    $user2 = User::create([
        'name' => 'Parent 2',
        'mobile' => '9876543211',
    ]);

    expect($user1->email)->toBeNull()
        ->and($user2->email)->toBeNull();
});

test('mobile number is normalized to +91 followed by 10 digits before saving', function () {
    $inputs = [
        '9876543210' => '+919876543210',
        '+919876543210' => '+919876543210',
        '09876543210' => '+919876543210',
        '+91 98765 43210' => '+919876543210',
        '98765-43210' => '+919876543210',
        '+91 (98765) 43210' => '+919876543210',
    ];

    foreach ($inputs as $input => $expected) {
        $user = new User([
            'name' => 'Normalizer Test',
            'mobile' => $input,
        ]);

        expect($user->mobile)->toBe($expected);
    }
});

test('creating a user whose mobile matches an existing one in a different format fails with a validation error on mobile not an exception', function () {
    User::factory()->create([
        'mobile' => '+919876543210',
    ]);

    $action = new CreateNewUser;

    try {
        $action->create([
            'name' => 'Duplicate Mobile User',
            'email' => 'newuser@example.com',
            'mobile' => '9876543210',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('mobile');
    }
});

test('invalid mobile numbers are rejected when submitted through profile settings', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $invalidNumbers = [
        '12345',
        '02212345678', // landline style
        '5876543210',  // starts with 5
    ];

    foreach ($invalidNumbers as $invalid) {
        expect(MobileNumber::isValid($invalid))->toBeFalse();

        Livewire::test(Profile::class)
            ->set('mobile', $invalid)
            ->call('updateProfileInformation')
            ->assertHasErrors(['mobile']);
    }
});

test('is_platform_admin is protected against mass assignment', function () {
    expect(fn () => User::create([
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'is_platform_admin' => true,
    ]))->toThrow(MassAssignmentException::class);

    $user = User::create([
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
    ]);

    expect($user->is_platform_admin)->toBeFalse();

    $user->is_platform_admin = true;
    $user->save();

    expect($user->fresh()->is_platform_admin)->toBeTrue();
});

test('mobile_verified_at is protected against mass assignment', function () {
    expect(fn () => User::create([
        'name' => 'Unverified Mobile User',
        'mobile' => '9876543210',
        'mobile_verified_at' => now(),
    ]))->toThrow(MassAssignmentException::class);

    $user = User::create([
        'name' => 'Unverified Mobile User',
        'mobile' => '9876543210',
    ]);

    expect($user->mobile_verified_at)->toBeNull();

    $now = now();
    $user->mobile_verified_at = $now;
    $user->save();

    expect($user->fresh()->mobile_verified_at)->not->toBeNull();
});

test('mobile_verified_at is cleared when mobile number is updated in profile', function () {
    $user = User::factory()->withMobile()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    expect($user->mobile_verified_at)->not->toBeNull();

    Livewire::test(Profile::class)
        ->set('mobile', '9876543299')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->mobile)->toBe('+919876543299')
        ->and($user->mobile_verified_at)->toBeNull();
});

test('factory states work correctly for mobileOnly, withMobile, and platformAdmin', function () {
    $mobileOnly = User::factory()->mobileOnly()->create();
    expect($mobileOnly->email)->toBeNull()
        ->and($mobileOnly->password)->toBeNull()
        ->and($mobileOnly->mobile)->toStartWith('+91')
        ->and($mobileOnly->mobile_verified_at)->not->toBeNull();

    $userWithMobile = User::factory()->withMobile()->create();
    expect($userWithMobile->email)->not->toBeNull()
        ->and($userWithMobile->mobile)->toStartWith('+91')
        ->and($userWithMobile->mobile_verified_at)->not->toBeNull();

    $admin = User::factory()->platformAdmin()->create();
    expect($admin->is_platform_admin)->toBeTrue();
});

test('email is stored trimmed and lowercased, or null when blank', function () {
    $user = new User([
        'name' => 'Case Test',
        'email' => '  User@Example.COM  ',
    ]);

    expect($user->email)->toBe('user@example.com');

    $blankUser = new User([
        'name' => 'Blank Test',
        'email' => '   ',
    ]);

    expect($blankUser->email)->toBeNull();
});
