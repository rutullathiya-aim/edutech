<?php

namespace Tests\Feature\Settings;

use App\Enums\SchoolRole;
use App\Livewire\Settings\Profile;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/settings/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_is_stored_in_lowercase_so_the_user_can_still_log_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', 'Test@Example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertEquals('test@example.com', $user->refresh()->email);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'Test@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_must_be_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);

        Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', 'Taken@Example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email']);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_mobile_verification_status_is_cleared_when_mobile_number_is_updated(): void
    {
        $user = User::factory()->withMobile()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->set('mobile', '9876543299')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('+919876543299', $user->mobile);
        $this->assertNull($user->mobile_verified_at);
    }

    public function test_mobile_verification_status_is_unchanged_when_mobile_number_is_unchanged(): void
    {
        $user = User::factory()->withMobile()->create();

        $this->actingAs($user);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->set('mobile', $user->mobile)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->mobile_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_changing_email_or_mobile_without_password_confirmation_is_refused(): void
    {
        $user = User::factory()->create([
            'email' => 'original@example.com',
            'mobile' => '+919876543210',
        ]);

        $this->actingAs($user); // No auth.password_confirmed_at session

        // Changing name only succeeds without password confirmation
        Livewire::test(Profile::class)
            ->set('name', 'Changed Name')
            ->set('email', 'original@example.com')
            ->set('mobile', '+919876543210')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertEquals('Changed Name', $user->fresh()->name);

        // Attempting to change email without password confirmation is refused
        $responseEmail = Livewire::test(Profile::class)
            ->set('email', 'new-email@example.com')
            ->call('updateProfileInformation');

        $responseEmail->assertStatus(403);
        $this->assertEquals('original@example.com', $user->fresh()->email);

        // Attempting to change mobile without password confirmation is refused
        $responseMobile = Livewire::test(Profile::class)
            ->set('mobile', '9876543299')
            ->call('updateProfileInformation');

        $responseMobile->assertStatus(403);
        $this->assertEquals('+919876543210', $user->fresh()->mobile);
    }

    public function test_changing_email_sends_notification_to_old_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'old-email@example.com',
        ]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        Livewire::test(Profile::class)
            ->set('name', $user->name)
            ->set('email', 'new-email@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertEquals('new-email@example.com', $user->fresh()->email);

        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            function (EmailChangedNotification $notification, array $channels, object $notifiable) {
                return $notifiable->routes['mail'] === 'old-email@example.com'
                    && $notification->oldEmail === 'old-email@example.com'
                    && $notification->newEmail === 'new-email@example.com';
            }
        );
    }

    public function test_user_with_active_membership_cannot_delete_their_account(): void
    {
        $this->seed(RoleSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->create();

        $membership = Membership::factory()->active()->create([
            'school_id' => $school->id,
            'user_id' => $user->id,
        ]);

        setPermissionsTeamId($school->id);
        $user->assignRole(SchoolRole::Teacher);

        $this->actingAs($user);

        // Form refuses deletion
        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $response->assertStatus(403);

        // User, membership, and roles must be untouched
        $this->assertNotNull($user->fresh());
        $this->assertNotNull($membership->fresh());
        expect($user->hasRole(SchoolRole::Teacher))->toBeTrue();

        // Profile view property hides the delete form
        $profile = Livewire::test(Profile::class);
        $this->assertFalse($profile->instance()->showDeleteUser());
    }
}
