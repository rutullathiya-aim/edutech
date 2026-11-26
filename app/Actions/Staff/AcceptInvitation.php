<?php

namespace App\Actions\Staff;

use App\Exceptions\InvalidInvitationException;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AcceptInvitation
{
    /**
     * Accept a pending staff invitation atomically for a logged-in user or new invitee account.
     *
     * @param  array{name: string, password: string}|null  $newAccountInput
     * @return array{user: User, school: School, membership: Membership, invitation: Invitation}
     */
    public function handle(
        #[SensitiveParameter] string $rawToken,
        ?User $actingUser = null,
        ?array $newAccountInput = null,
    ): array {
        if (trim($rawToken) === '') {
            throw new InvalidInvitationException;
        }

        return DB::transaction(function () use ($rawToken, $actingUser, $newAccountInput): array {
            $lockedInvitation = Invitation::withoutGlobalScope(SchoolScope::class)
                ->where('token_hash', Invitation::hashToken($rawToken))
                ->lockForUpdate()
                ->first();

            if (! $lockedInvitation instanceof Invitation || ! $lockedInvitation->isPending()) {
                throw new InvalidInvitationException;
            }

            $school = School::query()
                ->whereKey($lockedInvitation->school_id)
                ->first();

            if (! $school instanceof School || ! $school->isActive()) {
                throw new InvalidInvitationException;
            }

            $invitedEmail = $lockedInvitation->email;

            if (! is_string($invitedEmail) || trim($invitedEmail) === '') {
                throw new InvalidInvitationException;
            }

            $normalizedInvitedEmail = Str::lower(trim($invitedEmail));

            if ($actingUser instanceof User) {
                $user = User::query()
                    ->whereKey($actingUser->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (Str::lower((string) $user->email) !== $normalizedInvitedEmail) {
                    throw ValidationException::withMessages([
                        'invitation' => __('This invitation was sent to :email. Log out, sign in with that account, then open the invitation link again.', [
                            'email' => $lockedInvitation->maskedEmail(),
                        ]),
                    ]);
                }

                if ($user->email_verified_at === null) {
                    $user->email_verified_at = now();
                    $user->save();
                }

                $isNewAccount = false;
            } else {
                $existingUser = User::query()
                    ->where('email', $normalizedInvitedEmail)
                    ->lockForUpdate()
                    ->first();

                if ($existingUser instanceof User) {
                    throw ValidationException::withMessages([
                        'invitation' => __('You already have an account. Log in to accept.'),
                    ]);
                }

                $name = trim((string) ($newAccountInput['name'] ?? ''));
                $password = (string) ($newAccountInput['password'] ?? '');

                if ($name === '' || $password === '') {
                    throw ValidationException::withMessages([
                        'name' => __('Please complete all required account fields.'),
                    ]);
                }

                $user = new User([
                    'name' => $name,
                    'email' => $normalizedInvitedEmail,
                    'password' => $password,
                ]);
                $user->email_verified_at = now();
                $user->save();

                $isNewAccount = true;
            }

            $previousSchool = CurrentSchool::get();
            $previousTeamId = getPermissionsTeamId();
            CurrentSchool::set($school);

            try {
                $existingMembership = Membership::query()
                    ->where('school_id', $school->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingMembership instanceof Membership) {
                    throw ValidationException::withMessages([
                        'invitation' => __('A membership already exists for this account in this school.'),
                    ]);
                }

                $membership = new Membership([
                    'joined_at' => now(),
                ]);
                $membership->school_id = $school->id;
                $membership->user_id = $user->id;
                $membership->save();

                setPermissionsTeamId($school->id);
                $user->unsetRelation('roles');
                $user->unsetRelation('permissions');
                $user->assignRole($lockedInvitation->role->value);
                $user->unsetRelation('roles');
                $user->unsetRelation('permissions');

                $lockedInvitation->accept($user);

                $properties = [
                    'role' => $lockedInvitation->role->value,
                    'email' => $lockedInvitation->maskedEmail(),
                    'actor_id' => $user->id,
                    'created_account' => $isNewAccount,
                ];

                if ($isNewAccount) {
                    $properties['terms_version'] = (string) config('schools.terms_version', '2026-11-01');
                    $properties['terms_accepted_at'] = now()->toIso8601String();
                }

                activity('staff')
                    ->causedBy($user)
                    ->performedOn($lockedInvitation)
                    ->event('invitation_accepted')
                    ->withProperties($properties)
                    ->log('Staff invitation accepted');

                return [
                    'user' => $user,
                    'school' => $school,
                    'membership' => $membership,
                    'invitation' => $lockedInvitation,
                ];
            } finally {
                setPermissionsTeamId($previousTeamId);
                $user->unsetRelation('roles');
                $user->unsetRelation('permissions');

                if ($previousSchool instanceof School) {
                    CurrentSchool::set($previousSchool);
                } else {
                    CurrentSchool::forget();
                }
            }
        });
    }
}
