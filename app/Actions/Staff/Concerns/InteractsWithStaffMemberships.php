<?php

namespace App\Actions\Staff\Concerns;

use App\Enums\SchoolRole;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

trait InteractsWithStaffMemberships
{
    /**
     * Lock the target membership inside the current school and authorize the actor to update it.
     */
    protected function lockAndAuthorizeMembership(User $actor, Membership $membership): Membership
    {
        $school = CurrentSchool::getOrFail();

        $lockedMembership = Membership::query()
            ->where('school_id', $school->id)
            ->whereKey($membership->id)
            ->lockForUpdate()
            ->firstOrFail();

        Gate::forUser($actor)->authorize('update', $lockedMembership);

        return $lockedMembership;
    }

    /**
     * Lock remaining active Owner memberships for the school and verify at least one stays active.
     */
    protected function ensureOtherActiveOwnerRemains(School $school, Membership $lockedMembership): void
    {
        $anotherActiveOwnerExists = Membership::query()
            ->where('school_id', $school->id)
            ->active()
            ->whereKeyNot($lockedMembership->id)
            ->whereExists(static function (QueryBuilder $query) use ($school): void {
                $query->selectRaw('1')
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'memberships.user_id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where('model_has_roles.school_id', $school->id)
                    ->where('roles.name', SchoolRole::Owner->value);
            })
            ->lockForUpdate()
            ->exists();

        if (! $anotherActiveOwnerExists) {
            throw ValidationException::withMessages([
                'membership' => __('A school must always have at least one active Owner.'),
            ]);
        }
    }
}
