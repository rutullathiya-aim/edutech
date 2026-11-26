<?php

namespace App\Concerns;

use App\Enums\SubjectKind;
use App\Models\Subject;
use App\Tenant\CurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;

trait SubjectValidationRules
{
    /**
     * Get the validation rules used to validate subjects within the current school.
     *
     * @return array<string, array<int, Enum|Unique|ValidationRule|string>>
     */
    protected function subjectRules(?string $subjectId = null): array
    {
        return [
            'name' => $this->subjectNameRules($subjectId),
            'code' => $this->subjectCodeRules($subjectId),
            'kind' => $this->subjectKindRules(),
        ];
    }

    /**
     * Get validation rules for subject name (unique per current school).
     *
     * @return array<int, Unique|string>
     */
    protected function subjectNameRules(?string $subjectId = null): array
    {
        $schoolId = CurrentSchool::idOrFail();

        $uniqueRule = Rule::unique(Subject::class, 'name')
            ->where(fn ($query) => $query->where('school_id', $schoolId));

        if ($subjectId !== null) {
            $uniqueRule->ignore($subjectId);
        }

        return [
            'required',
            'string',
            'max:60',
            $uniqueRule,
        ];
    }

    /**
     * Get validation rules for optional subject code (unique per current school when set).
     *
     * @return array<int, Unique|string>
     */
    protected function subjectCodeRules(?string $subjectId = null): array
    {
        $schoolId = CurrentSchool::idOrFail();

        $uniqueRule = Rule::unique(Subject::class, 'code')
            ->where(fn ($query) => $query->where('school_id', $schoolId));

        if ($subjectId !== null) {
            $uniqueRule->ignore($subjectId);
        }

        return [
            'nullable',
            'string',
            'max:20',
            $uniqueRule,
        ];
    }

    /**
     * Get validation rules for subject kind.
     *
     * @return array<int, Enum|string>
     */
    protected function subjectKindRules(): array
    {
        return [
            'required',
            Rule::enum(SubjectKind::class),
        ];
    }
}
