<?php

namespace Database\Seeders;

use App\Enums\IndianState;
use App\Enums\MembershipStatus;
use App\Enums\SchoolBoard;
use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds for local visual exploration of the school picker and switcher.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $multiSchoolUser = $this->ensureUser('Test User', 'test@example.com');
        $singleSchoolUser = $this->ensureUser('Single School User', 'single@example.com');
        $this->ensureUser('No School User', 'noschool@example.com');
        $otherUser = $this->ensureUser('Other School User', 'other@example.com');

        $sunrise = $this->ensureSchool(
            name: 'Sunrise Public School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Cbse,
            city: 'Ahmedabad',
            state: IndianState::Gujarat,
            pincode: '380009',
        );

        $stXaviers = $this->ensureSchool(
            name: "St. Xavier's High School",
            status: SchoolStatus::Trial,
            board: SchoolBoard::Cisce,
            city: 'Pune',
            state: IndianState::Maharashtra,
            pincode: '411001',
            trialEndsAt: now()->addDays(30),
        );

        $greenValley = $this->ensureSchool(
            name: 'Green Valley Academy',
            status: SchoolStatus::Active,
            board: SchoolBoard::State,
            city: 'Bengaluru',
            state: IndianState::Karnataka,
            pincode: '560001',
        );

        $suspendedSchool = $this->ensureSchool(
            name: 'Archived Heritage School',
            status: SchoolStatus::Suspended,
            board: SchoolBoard::Cbse,
            city: 'Jaipur',
            state: IndianState::Rajasthan,
            pincode: '302001',
        );

        $disabledMembershipSchool = $this->ensureSchool(
            name: 'Former Oakridge School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Other,
            city: 'Surat',
            state: IndianState::Gujarat,
            pincode: '395007',
        );

        $foreignSchool = $this->ensureSchool(
            name: 'Maple Leaf International School',
            status: SchoolStatus::Active,
            board: SchoolBoard::Cbse,
            city: 'Mumbai',
            state: IndianState::Maharashtra,
            pincode: '400001',
        );

        $this->ensureMembership($multiSchoolUser, $sunrise, MembershipStatus::Active, SchoolRole::Owner);
        $this->ensureMembership($multiSchoolUser, $stXaviers, MembershipStatus::Active, SchoolRole::Teacher);
        $this->ensureMembership($multiSchoolUser, $greenValley, MembershipStatus::Active, SchoolRole::Parent);
        $this->ensureMembership($multiSchoolUser, $suspendedSchool, MembershipStatus::Active, SchoolRole::Admin);
        $this->ensureMembership($multiSchoolUser, $disabledMembershipSchool, MembershipStatus::Disabled);

        $this->ensureMembership($singleSchoolUser, $sunrise, MembershipStatus::Active, SchoolRole::Teacher);
        $this->ensureMembership($otherUser, $foreignSchool, MembershipStatus::Active, SchoolRole::Owner);

        setPermissionsTeamId(null);
    }

    /**
     * Find or create a verified demo user without overwriting existing passwords.
     */
    private function ensureUser(string $name, string $email): User
    {
        $user = User::where('email', $email)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => 'test@123',
        ]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * Find or create a school by name with explicit non-fillable status and trial bounds.
     */
    private function ensureSchool(
        string $name,
        SchoolStatus $status,
        SchoolBoard $board,
        string $city,
        IndianState $state,
        string $pincode,
        ?CarbonInterface $trialEndsAt = null,
    ): School {
        $school = School::withTrashed()->where('name', $name)->first();

        if ($school instanceof School) {
            if ($school->trashed()) {
                $school->restore();
            }

            return $school;
        }

        $school = new School([
            'name' => $name,
            'board' => $board,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
        ]);
        $school->status = $status;
        $school->trial_ends_at = $trialEndsAt;
        $school->save();

        return $school;
    }

    /**
     * Find or create a membership for a user and school, and optionally assign a school role.
     */
    private function ensureMembership(
        User $user,
        School $school,
        MembershipStatus $status,
        ?SchoolRole $role = null,
    ): Membership {
        $membership = Membership::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership instanceof Membership) {
            $membership = new Membership([
                'joined_at' => now(),
            ]);
            $membership->school_id = $school->id;
            $membership->user_id = $user->id;
            $membership->save();
        }

        if ($status === MembershipStatus::Disabled && $membership->isActive()) {
            $membership->disable();
        } elseif ($status === MembershipStatus::Active && ! $membership->isActive()) {
            $membership->activate();
        }

        if ($role !== null) {
            setPermissionsTeamId($school->id);
            if (! $user->hasRole($role->value)) {
                $user->assignRole($role->value);
            }
        }

        return $membership;
    }
}
