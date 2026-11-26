<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithInvitationAuditAndMail;
use App\Concerns\InvitationValidationRules;
use App\Enums\SchoolRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteStaff
{
    use InteractsWithInvitationAuditAndMail, InvitationValidationRules;

    /**
     * Validate, persist, audit, and notify a new staff invitation in the current school.
     */
    public function handle(User $actor, string $name, string $email, SchoolRole|string $role): Invitation
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('create', Invitation::class);

        $actorStaffRole = $actor->staffRoleForSchool($school->id);
        $assignableRoles = $actorStaffRole !== null ? SchoolRole::assignableBy($actorStaffRole) : [];

        $normalizedName = trim($name);
        $normalizedEmail = Str::lower(trim($email));
        $roleValue = $role instanceof SchoolRole ? $role->value : trim($role);

        Validator::make(
            [
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'role' => $roleValue,
            ],
            $this->staffInvitationRules($assignableRoles),
        )->validate();

        $targetRole = SchoolRole::from($roleValue);

        return DB::transaction(function () use ($school, $actor, $normalizedName, $normalizedEmail, $targetRole): Invitation {
            School::query()
                ->whereKey($school->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingUser = User::where('email', $normalizedEmail)->first();

            if ($existingUser instanceof User) {
                $existingMembership = Membership::query()
                    ->where('school_id', $school->id)
                    ->where('user_id', $existingUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingMembership instanceof Membership) {
                    if ($existingMembership->isActive()) {
                        throw ValidationException::withMessages([
                            'email' => __('Already on your staff'),
                        ]);
                    }

                    throw ValidationException::withMessages([
                        'email' => __('This person was disabled. Re-enable them instead.'),
                    ]);
                }
            }

            $openInvitations = Invitation::query()
                ->open()
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->get();

            foreach ($openInvitations as $openInvitation) {
                if ($openInvitation->isPending()) {
                    throw ValidationException::withMessages([
                        'email' => __('Already invited. Resend?'),
                    ]);
                }
            }

            $timezone = $school->timezone ?: 'Asia/Kolkata';
            $dailyLimit = (int) config('invitations.daily_limit', 50);
            $sentTodayCount = Invitation::query()
                ->where('created_at', '>=', now($timezone)->startOfDay()->utc())
                ->count();

            if ($sentTodayCount >= $dailyLimit) {
                throw ValidationException::withMessages([
                    'email' => __('Daily invitation limit of :limit reached for this school.', ['limit' => $dailyLimit]),
                ]);
            }

            foreach ($openInvitations as $expiredInvitation) {
                $expiredInvitation->revoke();
            }

            [$invitation, $rawToken] = Invitation::createWithToken([
                'school_id' => $school->id,
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'role' => $targetRole,
                'invited_by' => $actor->id,
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->logInvitationActivity(
                invitation: $invitation,
                actor: $actor,
                event: 'invitation_created',
                description: 'Staff invitation created',
            );

            $this->dispatchInvitationMailAfterCommit($school, $actor, $invitation, $rawToken);

            return $invitation;
        });
    }
}
