<?php

namespace App\Actions\Attendance;

use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateWeeklyOffDays
{
    /**
     * Validate, normalize, and save the school's configured weekly off ISO weekdays (1 = Mon .. 7 = Sun).
     *
     * @param  array<array-key, mixed>  $days
     */
    public function handle(User $actor, array $days): School
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('manageSettings', $school);

        $validated = Validator::make(
            ['weekly_off_days' => array_values($days)],
            [
                'weekly_off_days' => ['present', 'array', 'max:6'],
                'weekly_off_days.*' => ['integer', 'between:1,7', 'distinct'],
            ],
            [
                'weekly_off_days.max' => __('At least one day of the week must remain a working day.'),
                'weekly_off_days.*.between' => __('Weekly off days must use ISO weekday numbers from 1 (Monday) to 7 (Sunday).'),
                'weekly_off_days.*.distinct' => __('Duplicate weekly off days are not allowed.'),
            ],
        )->validate();

        /** @var list<mixed> $validatedDays */
        $validatedDays = $validated['weekly_off_days'];
        $normalized = School::normalizeWeeklyOffDays($validatedDays);

        return DB::transaction(function () use ($actor, $school, $normalized): School {
            $lockedSchool = School::query()
                ->whereKey($school->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('manageSettings', $lockedSchool);

            $oldDays = $lockedSchool->weeklyOffDays();

            if ($oldDays === $normalized) {
                return $lockedSchool;
            }

            $lockedSchool->weekly_off_days = $normalized;
            $lockedSchool->save();

            activity('school')
                ->causedBy($actor)
                ->performedOn($lockedSchool)
                ->event('weekly_off_days_updated')
                ->withProperties([
                    'old_weekly_off_days' => $oldDays,
                    'new_weekly_off_days' => $normalized,
                ])
                ->log('Updated weekly off days');

            CurrentSchool::set($lockedSchool);

            return $lockedSchool;
        });
    }
}
