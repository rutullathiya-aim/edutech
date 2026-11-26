<?php

namespace App\Concerns;

use App\Models\AcademicYear;
use App\Rules\AcademicYearDateRange;
use App\Tenant\CurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait AcademicYearValidationRules
{
    /**
     * Get the validation rules used to validate academic years within the current school.
     *
     * @return array<string, array<int, Unique|ValidationRule|string>>
     */
    protected function academicYearRules(?string $academicYearId = null): array
    {
        return [
            'name' => $this->academicYearNameRules($academicYearId),
            'starts_on' => $this->academicYearStartsOnRules(),
            'ends_on' => $this->academicYearEndsOnRules($academicYearId),
        ];
    }

    /**
     * Get validation rules for academic year name (format YYYY-YY, unique per school).
     *
     * @return array<int, Unique|string>
     */
    protected function academicYearNameRules(?string $academicYearId = null): array
    {
        $uniqueRule = Rule::unique(AcademicYear::class, 'name')
            ->where(fn ($query) => $query->where('school_id', CurrentSchool::idOrFail()));

        if ($academicYearId !== null) {
            $uniqueRule->ignore($academicYearId);
        }

        return [
            'required',
            'string',
            'max:20',
            'regex:/^\d{4}-\d{2}$/',
            $uniqueRule,
        ];
    }

    /**
     * Get validation rules for academic year start date.
     *
     * @return array<int, string>
     */
    protected function academicYearStartsOnRules(): array
    {
        return ['required', 'date'];
    }

    /**
     * Get validation rules for academic year end date, including max duration and overlap checks.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function academicYearEndsOnRules(?string $academicYearId = null): array
    {
        return [
            'required',
            'date',
            'after:starts_on',
            new AcademicYearDateRange($academicYearId),
        ];
    }
}
