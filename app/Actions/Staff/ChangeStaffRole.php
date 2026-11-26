<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithStaffMemberships;
use App\Enums\SchoolRole;
use App\Models\Membership;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ChangeStaffRole
{
    use InteractsWithStaffMemberships;

    /**
     * Replace a staff member's current-school staff role inside a transaction while preserving non-staff and other-school roles.
     */
    public function handle(User $actor, Membership $membership, SchoolRole|string $newRole): Membership
    {
        $school = CurrentSchool::getOrFail();

        return DB::transaction(function () use ($school, $actor, $membership, $newRole): Membership {
            $lockedMembership = $this->lockAndAuthorizeMembership($actor, $membership);

            $actorStaffRole = $actor->staffRoleForSchool($school->id);
            $assignableRoles = $actorStaffRole !== null ? SchoolRole::assignableBy($actorStaffRole) : [];
            $roleValue = $newRole instanceof SchoolRole ? $newRole->value : trim($newRole);

            Validator::make(
                ['role' => $roleValue],
                ['role' => ['required', Rule::enum(SchoolRole::class)->only($assignableRoles)]],
            )->validate();

            $targetNewRole = SchoolRole::from($roleValue);

            $targetUser = User::query()
                ->whereKey($lockedMembership->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldRole = $targetUser->staffRoleForSchool($school->id);

            if ($oldRole === SchoolRole::Owner) {
                $this->ensureOtherActiveOwnerRemains($school, $lockedMembership);
            }

            if ($oldRole === $targetNewRole) {
                return $lockedMembership;
            }

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($school->id);

            try {
                $targetUser->unsetRelation('roles');
                $targetUser->unsetRelation('permissions');

                foreach (SchoolRole::staffCases() as $staffRole) {
                    if ($targetUser->hasRole($staffRole->value)) {
                        $targetUser->removeRole($staffRole->value);
                    }
                }

                $targetUser->assignRole($targetNewRole->value);
                $targetUser->unsetRelation('roles');
                $targetUser->unsetRelation('permissions');
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            activity('staff')
                ->causedBy($actor)
                ->performedOn($lockedMembership)
                ->event('role_changed')
                ->withProperties([
                    'old_role' => $oldRole?->value,
                    'new_role' => $targetNewRole->value,
                    'membership_id' => $lockedMembership->id,
                    'target_user_id' => $targetUser->id,
                    'actor_id' => $actor->id,
                ])
                ->log('Staff role changed');

            return $lockedMembership;
        });
    }
}
