<?php

namespace App\Concerns;

use App\Models\Grade;
use App\Models\Subject;
use App\Tenant\CurrentSchool;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait GradeSubjectValidationRules
{
    /**
     * Get the validation rules used to sync one grade's subject curriculum.
     *
     * @return array<string, array<int, Closure|Exists|string>>
     */
    protected function gradeSubjectSyncRules(): array
    {
        $schoolId = CurrentSchool::idOrFail();

        return [
            'grade_id' => [
                'bail',
                'required',
                'string',
                Rule::exists(Grade::class, 'id')
                    ->where(fn ($query) => $query->where('school_id', $schoolId)),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (Grade::find($value)?->isArchived()) {
                        $fail('The selected grade is archived.');
                    }
                },
            ],
            'items' => ['array'],
            'items.*.subject_id' => [
                'bail',
                'required',
                'string',
                'distinct',
                Rule::exists(Subject::class, 'id')
                    ->where(fn ($query) => $query->where('school_id', $schoolId)),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (Subject::find($value)?->isArchived()) {
                        $fail('An archived subject cannot be assigned to a grade.');
                    }
                },
            ],
            'items.*.is_optional' => ['required', 'boolean'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:200'],
        ];
    }
}
