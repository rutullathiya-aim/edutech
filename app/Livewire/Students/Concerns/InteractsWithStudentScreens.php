<?php

namespace App\Livewire\Students\Concerns;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Support\IndianDate;
use App\Support\MobileNumber;
use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

trait InteractsWithStudentScreens
{
    /**
     * Get the active school from tenant context.
     */
    #[Computed]
    public function school(): School
    {
        return CurrentSchool::getOrFail();
    }

    /**
     * Determine whether the signed-in user may view sensitive student fields.
     */
    #[Computed]
    public function canViewSensitive(): bool
    {
        return auth()->user()?->can('viewSensitive', Student::class) ?? false;
    }

    /**
     * Get academic years for the current school ordered newest first.
     *
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get active (unarchived) grades for the current school ordered by sort_order.
     *
     * @return Collection<int, Grade>
     */
    #[Computed]
    public function activeGrades(): Collection
    {
        return Grade::query()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Parse a dd/mm/yyyy UI date string into Y-m-d or throw a validation exception on the field.
     *
     * @throws ValidationException
     */
    protected function parseUiDateOrFail(string $field, string $value): string
    {
        $parsed = IndianDate::toDatabaseDate($value);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                $field => __('Enter a valid date in dd/mm/yyyy format (for example, 05/06/2015).'),
            ]);
        }

        return $parsed;
    }

    /**
     * Find guardians in the current school matching a typed mobile number for sibling linking suggestions.
     *
     * @return list<array{id: string, name: string, mobile: string, label: string}>
     */
    protected function findSiblingGuardianSuggestions(?string $rawMobile): array
    {
        $normalizedMobile = MobileNumber::normalize($rawMobile);

        if ($normalizedMobile === null || ! MobileNumber::isValid($normalizedMobile)) {
            return [];
        }

        $guardians = Guardian::query()
            ->where('mobile', $normalizedMobile)
            ->with([
                'studentGuardians' => static function ($query): void {
                    $query->with([
                        'student:id,school_id,name',
                        'student.enrollments' => static function ($enrollmentQuery): void {
                            $enrollmentQuery->with([
                                'academicYear:id,school_id,is_current,starts_on',
                                'section:id,school_id,grade_id,academic_year_id,name',
                                'section.grade:id,school_id,name',
                            ]);
                        },
                    ]);
                },
            ])
            ->limit(5)
            ->get();

        $suggestions = [];

        foreach ($guardians as $guardian) {
            $suggestions[] = [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'mobile' => $guardian->mobile ?? '',
                'label' => $this->formatSiblingSuggestionLabel($guardian),
            ];
        }

        return $suggestions;
    }

    /**
     * Format a human-readable sibling link prompt like "Ramesh Patel (Father of Aarav, Grade 3 - A)".
     */
    protected function formatSiblingSuggestionLabel(Guardian $guardian): string
    {
        $parts = [];

        foreach ($guardian->studentGuardians as $link) {
            $student = $link->student;

            $latestEnrollment = $student->enrollments
                ->sortByDesc(static fn ($enrollment): string => ($enrollment->academicYear->is_current ? '1' : '0').$enrollment->academicYear->starts_on->format('Y-m-d'))
                ->first();

            $classLabel = $latestEnrollment !== null
                ? "{$latestEnrollment->section->grade->name} - {$latestEnrollment->section->name}"
                : __('Unenrolled');

            $parts[] = __(':relationship of :student (:class)', [
                'relationship' => $link->relationship->label(),
                'student' => $student->name,
                'class' => $classLabel,
            ]);
        }

        if ($parts === []) {
            return $guardian->name;
        }

        return $guardian->name.' ('.implode('; ', $parts).')';
    }
}
