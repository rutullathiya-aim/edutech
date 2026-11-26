<?php

namespace App\Concerns;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Tenant\CurrentSchool;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait SectionValidationRules
{
    /**
     * Get the validation rules used to validate sections within the current school.
     *
     * @return array<string, array<int, Closure|Exists|Unique|ValidationRule|string>>
     */
    protected function sectionRules(
        ?string $academicYearId = null,
        ?string $gradeId = null,
        ?string $sectionId = null,
    ): array {
        return [
            'academic_year_id' => $this->sectionAcademicYearRules(),
            'grade_id' => $this->sectionGradeRules(),
            'name' => $this->sectionNameRules($academicYearId, $gradeId, $sectionId),
            'capacity' => $this->sectionCapacityRules(),
            'room' => $this->sectionRoomRules(),
        ];
    }

    /**
     * Get validation rules for academic_year_id (exists in current school and not closed).
     *
     * @return array<int, Closure|Exists|string>
     */
    protected function sectionAcademicYearRules(): array
    {
        $schoolId = CurrentSchool::idOrFail();

        return [
            'bail',
            'required',
            'string',
            Rule::exists(AcademicYear::class, 'id')
                ->where(fn ($query) => $query->where('school_id', $schoolId)),
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                if (AcademicYear::find($value)?->isClosed()) {
                    $fail('The selected academic year is closed.');
                }
            },
        ];
    }

    /**
     * Get validation rules for grade_id (exists in current school and not archived).
     *
     * @return array<int, Closure|Exists|string>
     */
    protected function sectionGradeRules(): array
    {
        $schoolId = CurrentSchool::idOrFail();

        return [
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
        ];
    }

    /**
     * Get validation rules for section name (max 10, letters/digits/spaces, unique per school + year + grade).
     *
     * @return array<int, Unique|string>
     */
    protected function sectionNameRules(
        ?string $academicYearId = null,
        ?string $gradeId = null,
        ?string $sectionId = null,
    ): array {
        $schoolId = CurrentSchool::idOrFail();

        $uniqueRule = Rule::unique(Section::class, 'name')
            ->where(fn ($query) => $query
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $academicYearId)
                ->where('grade_id', $gradeId));

        if ($sectionId !== null) {
            $uniqueRule->ignore($sectionId);
        }

        return [
            'required',
            'string',
            'max:10',
            'regex:/^[\pL\pN ]+$/u',
            $uniqueRule,
        ];
    }

    /**
     * Get validation rules for optional section capacity (1-200).
     *
     * @return array<int, string>
     */
    protected function sectionCapacityRules(): array
    {
        return ['nullable', 'integer', 'min:1', 'max:200'];
    }

    /**
     * Get validation rules for optional room name (max 30).
     *
     * @return array<int, string>
     */
    protected function sectionRoomRules(): array
    {
        return ['nullable', 'string', 'max:30'];
    }
}
