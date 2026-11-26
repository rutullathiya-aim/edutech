<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\InteractsWithStaffMemberships;
use App\Models\Membership;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;

class EnableMembership
{
    use InteractsWithStaffMemberships;

    /**
     * Re-enable a disabled staff membership, preserving its existing roles and logging an audit entry.
     */
    public function handle(User $actor, Membership $membership): Membership
    {
        CurrentSchool::getOrFail();

        return DB::transaction(function () use ($actor, $membership): Membership {
            $lockedMembership = $this->lockAndAuthorizeMembership($actor, $membership);

            if ($lockedMembership->isActive()) {
                return $lockedMembership;
            }

            $lockedMembership->activate();

            activity('staff')
                ->causedBy($actor)
                ->performedOn($lockedMembership)
                ->event('membership_enabled')
                ->withProperties([
                    'membership_id' => $lockedMembership->id,
                    'target_user_id' => $lockedMembership->user_id,
                    'role' => $lockedMembership->staffRole()?->value,
                    'actor_id' => $actor->id,
                ])
                ->log('Staff membership re-enabled');

            return $lockedMembership;
        });
    }
}
