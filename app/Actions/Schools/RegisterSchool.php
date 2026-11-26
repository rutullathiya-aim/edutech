<?php

namespace App\Actions\Schools;

use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterSchool
{
    /**
     * Register a new school, owner user, active membership, Owner role, and audit entry.
     *
     * @param  array{
     *     school_name: string,
     *     state: string,
     *     city: string,
     *     board?: string|null,
     *     name: string,
     *     email: string,
     *     mobile: string,
     *     password: string,
     * }  $input
     * @return array{user: User, school: School}
     */
    public function handle(array $input): array
    {
        $normalizedEmail = Str::lower(trim($input['email']));
        $normalizedMobile = MobileNumber::normalize($input['mobile']);

        /** @var array{user: User, school: School} $result */
        $result = DB::transaction(function () use ($input, $normalizedEmail, $normalizedMobile): array {
            $user = User::create([
                'name' => trim($input['name']),
                'email' => $normalizedEmail,
                'mobile' => $normalizedMobile,
                'password' => $input['password'],
            ]);

            $school = new School([
                'name' => trim($input['school_name']),
                'state' => $input['state'],
                'city' => trim($input['city']),
                'board' => filled($input['board'] ?? null) ? $input['board'] : null,
            ]);
            $school->status = SchoolStatus::Trial;
            $timezone = $school->timezone ?: 'Asia/Kolkata';
            $trialDays = (int) config('schools.trial_days', 30);
            $school->trial_ends_at = now($timezone)->addDays($trialDays)->endOfDay()->startOfSecond()->utc();
            $school->save();

            $membership = new Membership([
                'joined_at' => now(),
            ]);
            $membership->user_id = $user->id;
            $school->memberships()->save($membership);

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($school->id);

            try {
                $user->assignRole(SchoolRole::Owner->value);
            } finally {
                setPermissionsTeamId($previousTeamId);
                $user->unsetRelation('roles');
                $user->unsetRelation('permissions');
            }

            activity('school')
                ->causedBy($user)
                ->performedOn($school)
                ->event('school_registered')
                ->withProperties([
                    'terms_version' => (string) config('schools.terms_version', '2026-11-01'),
                    'terms_accepted_at' => now()->toIso8601String(),
                ])
                ->log('School registered');

            return [
                'user' => $user,
                'school' => $school,
            ];
        });

        event(new Registered($result['user']));

        return $result;
    }
}
