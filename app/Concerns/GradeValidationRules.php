<?php

namespace App\Concerns;

use App\Models\Grade;
use App\Tenant\CurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait GradeValidationRules
{
    /**
     * Get the validation rules used to validate grades within the current school.
     *
     * @return array<string, array<int, Unique|ValidationRule|string>>
     */
    protected function gradeRules(?string $gradeId = null): array
    {
        return [
            'name' => $this->gradeNameRules($gradeId),
            'short_name' => $this->gradeShortNameRules(),
            'sort_order' => $this->gradeSortOrderRules($gradeId),
        ];
    }

    /**
     * Get validation rules for grade name (unique per current school).
     *
     * @return array<int, Unique|string>
     */
    protected function gradeNameRules(?string $gradeId = null): array
    {
        $uniqueRule = Rule::unique(Grade::class, 'name')
            ->where(fn ($query) => $query->where('school_id', CurrentSchool::idOrFail()));

        if ($gradeId !== null) {
            $uniqueRule->ignore($gradeId);
        }

        return [
            'required',
            'string',
            'max:30',
            $uniqueRule,
        ];
    }

    /**
     * Get validation rules for optional grade short name.
     *
     * @return array<int, string>
     */
    protected function gradeShortNameRules(): array
    {
        return ['nullable', 'string', 'max:10'];
    }

    /**
     * Get validation rules for grade sort order (0-50, unique per current school).
     *
     * @return array<int, Unique|string>
     */
    protected function gradeSortOrderRules(?string $gradeId = null): array
    {
        $uniqueRule = Rule::unique(Grade::class, 'sort_order')
            ->where(fn ($query) => $query->where('school_id', CurrentSchool::idOrFail()));

        if ($gradeId !== null) {
            $uniqueRule->ignore($gradeId);
        }

        return [
            'required',
            'integer',
            'min:0',
            'max:50',
            $uniqueRule,
        ];
    }
}
