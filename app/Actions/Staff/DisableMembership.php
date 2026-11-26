<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithStaffMemberships;
use App\Enums\SchoolRole;
use App\Models\Membership;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;

class DisableMembership
{
    use InteractsWithStaffMemberships;

    /**
     * Disable an active staff membership without removing roles or touching other schools.
     */
    public function handle(User $actor, Membership $membership): Membership
    {
        $school = CurrentSchool::getOrFail();

        return DB::transaction(function () use ($school, $actor, $membership): Membership {
            $lockedMembership = $this->lockAndAuthorizeMembership($actor, $membership);

            if (! $lockedMembership->isActive()) {
                return $lockedMembership;
            }

            $targetRole = $lockedMembership->staffRole();

            if ($targetRole === SchoolRole::Owner) {
                $this->ensureOtherActiveOwnerRemains($school, $lockedMembership);
            }

            $lockedMembership->disable();

            activity('staff')
                ->causedBy($actor)
                ->performedOn($lockedMembership)
                ->event('membership_disabled')
                ->withProperties([
                    'membership_id' => $lockedMembership->id,
                    'target_user_id' => $lockedMembership->user_id,
                    'role' => $targetRole?->value,
                    'actor_id' => $actor->id,
                ])
                ->log('Staff membership disabled');

            return $lockedMembership;
        });
    }
}
