<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $school = School::factory()->create();

        $response = $this->get(route('dashboard', ['school' => $school->id]));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();

        Membership::factory()->create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard', ['school' => $school->id]));
        $response->assertOk();
    }
}
