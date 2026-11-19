<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_gives_identical_responses_for_known_and_unknown_emails(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'registered@example.com']);

        $knownResponse = $this->post(route('password.email'), ['email' => 'registered@example.com']);
        $unknownResponse = $this->post(route('password.email'), ['email' => 'unregistered@example.com']);

        $expectedMessage = 'If an account exists for that email, a reset link has been sent.';

        $knownResponse->assertStatus(302)
            ->assertSessionHas('status', $expectedMessage)
            ->assertSessionHasNoErrors();

        $unknownResponse->assertStatus(302)
            ->assertSessionHas('status', $expectedMessage)
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }

    public function test_stored_job_payload_for_reset_email_does_not_contain_raw_token(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create(['email' => 'queued_reset@example.com']);

        $this->post(route('password.email'), ['email' => $user->email]);

        $job = DB::table('jobs')->latest('id')->first();

        $this->assertNotNull($job);

        /** @var array{data: array{command: string}} $payload */
        $payload = json_decode((string) $job->payload, true);
        $this->assertNotNull($payload);

        $encryptedCommand = $payload['data']['command'];
        /** @var SendQueuedNotifications $decryptedCommand */
        $decryptedCommand = unserialize(decrypt($encryptedCommand));

        /** @var ResetPasswordNotification $notification */
        $notification = $decryptedCommand->notification;
        $rawToken = $notification->token;
        $this->assertNotEmpty($rawToken);

        $this->assertStringNotContainsString($rawToken, (string) $job->payload);
    }
}
