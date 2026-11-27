<?php

namespace App\Actions\Attendance;

use App\Actions\Students\Import\StudentImportColumns;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Holiday;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AddHoliday
{
    /**
     * Validate and create a planned holiday within an open academic year of the current school.
     */
    public function handle(User $actor, AcademicYear $academicYear, string $date, string $name): Holiday
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('create', Holiday::class);

        $trimmedName = trim($name);

        $validated = Validator::make(
            [
                'date' => trim($date),
                'name' => $trimmedName,
            ],
            [
                'date' => ['required', 'date'],
                'name' => ['required', 'string', 'max:100'],
            ],
            [
                'date.required' => __('Please choose a holiday date.'),
                'date.date' => __('Please enter a valid holiday date.'),
                'name.required' => __('Please enter the holiday name.'),
                'name.max' => __('Holiday name may not exceed 100 characters.'),
            ],
        )->validate();

        $holidayDate = CarbonImmutable::parse((string) $validated['date'])->startOfDay();
        $validatedName = (string) $validated['name'];

        if (StudentImportColumns::startsWithDisallowedFormulaCharacter($validatedName)) {
            throw ValidationException::withMessages([
                'name' => __('Holiday name cannot start with = or @.'),
            ]);
        }

        return DB::transaction(function () use ($school, $academicYear, $holidayDate, $validatedName): Holiday {
            $lockedYear = AcademicYear::query()
                ->where('school_id', $school->id)
                ->whereKey($academicYear->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedYear->isClosed()) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('Cannot add holidays in a closed academic year.'),
                ]);
            }

            if (
                $holidayDate->lessThan($lockedYear->starts_on->startOfDay())
                || $holidayDate->greaterThan($lockedYear->ends_on->startOfDay())
            ) {
                throw ValidationException::withMessages([
                    'date' => __('Holiday date must fall between :start and :end for :year.', [
                        'start' => $lockedYear->starts_on->format('d/m/Y'),
                        'end' => $lockedYear->ends_on->format('d/m/Y'),
                        'year' => $lockedYear->name,
                    ]),
                ]);
            }

            $duplicateExists = Holiday::query()
                ->whereDate('date', $holidayDate->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'date' => __('A holiday is already recorded for this date.'),
                ]);
            }

            if (
                AttendanceSession::query()
                    ->where('date', $holidayDate->toDateString())
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'date' => __('Cannot mark a date with recorded attendance as a holiday. Delete those attendance sessions first, or skip this date.'),
                ]);
            }

            return Holiday::create([
                'academic_year_id' => $lockedYear->id,
                'date' => $holidayDate->toDateString(),
                'name' => $validatedName,
            ]);
        });
    }
}
