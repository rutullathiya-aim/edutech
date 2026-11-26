<?php

namespace App\Actions\AcademicYears;

use App\Models\AcademicYear;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use LogicException;

class MakeAcademicYearCurrent
{
    /**
     * Mark the given academic year as the sole current year for its school.
     */
    public function handle(AcademicYear $academicYear): AcademicYear
    {
        if (CurrentSchool::check() && $academicYear->school_id !== CurrentSchool::idOrFail()) {
            throw new LogicException('Cannot mark an academic year current for a different school.');
        }

        if ($academicYear->isClosed()) {
            throw new LogicException('A closed academic year cannot be marked as current.');
        }

        return DB::transaction(function () use ($academicYear): AcademicYear {
            if ($academicYear->is_current) {
                return $academicYear;
            }

            $existingCurrent = AcademicYear::query()
                ->where('school_id', $academicYear->school_id)
                ->where('is_current', true)
                ->where('id', '!=', $academicYear->id)
                ->lockForUpdate()
                ->first();

            if ($existingCurrent instanceof AcademicYear) {
                $existingCurrent->is_current = false;
                $existingCurrent->save();
            }

            $academicYear->is_current = true;
            $academicYear->save();

            activity('academic_year')
                ->causedBy(auth()->user())
                ->performedOn($academicYear)
                ->event('academic_year_marked_current')
                ->withProperties([
                    'previous_academic_year_id' => $existingCurrent?->id,
                    'previous_academic_year_name' => $existingCurrent?->name,
                ])
                ->log('Academic year marked as current');

            return $academicYear->refresh();
        });
    }
}
