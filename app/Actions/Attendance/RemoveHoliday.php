<?php

namespace App\Actions\Attendance;

use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveHoliday
{
    /**
     * Remove a planned holiday from an open academic year of the current school.
     */
    public function handle(User $actor, Holiday $holiday): void
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('delete', $holiday);

        DB::transaction(function () use ($actor, $school, $holiday): void {
            $lockedHoliday = Holiday::query()
                ->whereKey($holiday->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('delete', $lockedHoliday);

            $academicYear = AcademicYear::query()
                ->where('school_id', $school->id)
                ->whereKey($lockedHoliday->academic_year_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($academicYear->isClosed()) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('Cannot remove holidays from a closed academic year.'),
                ]);
            }

            $lockedHoliday->delete();
        });
    }
}
