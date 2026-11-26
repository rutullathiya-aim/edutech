<?php

namespace App\Concerns;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\SocialCategory;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Support\MobileNumber;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Throwable;

trait StudentValidationRules
{
    /**
     * Normalize student profile payload before validation and persistence.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeStudentData(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            if ($value instanceof BackedEnum) {
                $normalized[$key] = $value->value;

                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed !== '' ? $trimmed : null;

                continue;
            }

            $normalized[$key] = $value;
        }

        if (! array_key_exists('nationality', $normalized) || $normalized['nationality'] === null) {
            $normalized['nationality'] = 'Indian';
        }

        return $normalized;
    }

    /**
     * Normalize a single guardian link payload before validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeGuardianData(array $input): array
    {
        $guardianId = $input['guardian_id'] ?? null;
        $relationship = $input['relationship'] ?? null;
        $rawMobile = $input['mobile'] ?? null;
        $rawEmail = $input['email'] ?? null;
        $rawOccupation = $input['occupation'] ?? null;
        $rawName = $input['name'] ?? null;

        return [
            'guardian_id' => is_string($guardianId) && trim($guardianId) !== '' ? trim($guardianId) : null,
            'name' => is_string($rawName) && trim($rawName) !== '' ? trim($rawName) : null,
            'mobile' => is_string($rawMobile) ? MobileNumber::normalize($rawMobile) : null,
            'email' => is_string($rawEmail) && trim($rawEmail) !== '' ? Str::lower(trim($rawEmail)) : null,
            'occupation' => is_string($rawOccupation) && trim($rawOccupation) !== '' ? trim($rawOccupation) : null,
            'relationship' => $relationship instanceof BackedEnum
                ? $relationship->value
                : (is_string($relationship) && trim($relationship) !== '' ? trim($relationship) : null),
            'is_primary' => (bool) ($input['is_primary'] ?? false),
        ];
    }

    /**
     * Get validation rules for creating or updating a student profile within a school.
     *
     * @return array<string, array<int, Closure|Enum|Unique|ValidationRule|string>>
     */
    protected function studentProfileRules(
        string $schoolId,
        ?string $ignoreStudentId = null,
        mixed $dateOfBirth = null,
    ): array {
        $uniqueAdmissionRule = Rule::unique(Student::class, 'admission_no')
            ->where(fn ($query) => $query->where('school_id', $schoolId));

        $uniqueApaarRule = Rule::unique(Student::class, 'apaar_id')
            ->where(fn ($query) => $query->where('school_id', $schoolId));

        if ($ignoreStudentId !== null) {
            $uniqueAdmissionRule->ignore($ignoreStudentId);
            $uniqueApaarRule->ignore($ignoreStudentId);
        }

        return [
            'admission_no' => ['required', 'string', 'max:30', $uniqueAdmissionRule],
            'admission_date' => $this->studentAdmissionDateRules($dateOfBirth),
            'name' => ['required', 'string', 'max:150'],
            'date_of_birth' => $this->studentDateOfBirthRules(),
            'gender' => ['required', Rule::enum(Gender::class)],
            'social_category' => ['nullable', Rule::enum(SocialCategory::class)],
            'religion' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:50'],
            'mother_tongue' => ['nullable', 'string', 'max:50'],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],
            'apaar_id' => ['nullable', 'string', 'regex:/^[0-9]{12}$/', $uniqueApaarRule],
            'pen' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'aadhaar_last4' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::enum(IndianState::class)],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'previous_school' => ['nullable', 'string', 'max:150'],
            'previous_tc_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Get validation rules for admission date (on or after date of birth, and not more than one year in the future).
     *
     * @return array<int, Closure|string>
     */
    protected function studentAdmissionDateRules(mixed $dateOfBirth = null): array
    {
        return [
            'bail',
            'required',
            'date',
            static function (string $attribute, mixed $value, Closure $fail) use ($dateOfBirth): void {
                if (! is_string($value) && ! $value instanceof DateTimeInterface) {
                    return;
                }

                $admissionDate = CarbonImmutable::parse($value)->startOfDay();
                $maxAllowedDate = CarbonImmutable::today()->addYear()->startOfDay();

                if ($admissionDate->greaterThan($maxAllowedDate)) {
                    $fail('The admission date cannot be more than one year in the future.');

                    return;
                }

                if (is_string($dateOfBirth) || $dateOfBirth instanceof DateTimeInterface) {
                    try {
                        $dob = CarbonImmutable::parse($dateOfBirth)->startOfDay();
                    } catch (Throwable) {
                        return;
                    }

                    if ($admissionDate->lessThan($dob)) {
                        $fail('The admission date must be on or after the date of birth.');
                    }
                }
            },
        ];
    }

    /**
     * Get validation rules for student date of birth (in the past, student aged 2 to 20 years).
     *
     * @return array<int, Closure|string>
     */
    protected function studentDateOfBirthRules(): array
    {
        return [
            'bail',
            'required',
            'date',
            'before:today',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) && ! $value instanceof DateTimeInterface) {
                    return;
                }

                $dob = CarbonImmutable::parse($value)->startOfDay();
                $today = CarbonImmutable::today();

                if ($dob->greaterThan($today->subYears(2)) || $dob->lessThan($today->subYears(20))) {
                    $fail('The date of birth must indicate a student aged between 2 and 20 years.');
                }
            },
        ];
    }

    /**
     * Get validation rules for attaching or creating a single guardian link.
     *
     * @return array<string, array<int, Closure|Enum|Exists|ValidationRule|string>>
     */
    protected function guardianLinkRules(string $schoolId): array
    {
        return [
            'guardian_id' => [
                'nullable',
                'string',
                Rule::exists(Guardian::class, 'id')->where(fn ($query) => $query->where('school_id', $schoolId)),
            ],
            'name' => ['required_without:guardian_id', 'nullable', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'regex:'.MobileNumber::REGEX],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'relationship' => ['required', Rule::enum(GuardianRelationship::class)],
            'is_primary' => ['boolean'],
        ];
    }

    /**
     * Get validation rules for placing a student into an academic year and section.
     *
     * @return array<string, array<int, Closure|Exists|Unique|ValidationRule|string>>
     */
    protected function enrollmentPlacementRules(
        string $schoolId,
        ?string $academicYearId,
        ?string $sectionId,
        ?string $gradeId = null,
        ?string $ignoreEnrollmentId = null,
    ): array {
        $rollUniqueRule = Rule::unique(Enrollment::class, 'roll_number')
            ->where(fn ($query) => $query
                ->where('school_id', $schoolId)
                ->where('section_id', $sectionId ?? ''));

        if ($ignoreEnrollmentId !== null) {
            $rollUniqueRule->ignore($ignoreEnrollmentId);
        }

        return [
            'academic_year_id' => [
                'bail',
                'required',
                'string',
                Rule::exists(AcademicYear::class, 'id')->where(fn ($query) => $query->where('school_id', $schoolId)),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (AcademicYear::find($value)?->isClosed()) {
                        $fail('Cannot enroll a student in a closed academic year.');
                    }
                },
            ],
            'section_id' => [
                'bail',
                'required',
                'string',
                Rule::exists(Section::class, 'id')->where(function ($query) use ($schoolId, $academicYearId, $gradeId): void {
                    $query->where('school_id', $schoolId);

                    if ($academicYearId !== null) {
                        $query->where('academic_year_id', $academicYearId);
                    }

                    if ($gradeId !== null) {
                        $query->where('grade_id', $gradeId);
                    }
                }),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $section = Section::find($value);

                    if ($section instanceof Section && Grade::find($section->grade_id)?->isArchived()) {
                        $fail('Cannot enroll a student into a section of an archived grade.');
                    }
                },
            ],
            'roll_number' => ['nullable', 'integer', 'min:1', 'max:65535', $rollUniqueRule],
            'enrolled_on' => [
                'bail',
                'required',
                'date',
                static function (string $attribute, mixed $value, Closure $fail) use ($academicYearId): void {
                    if ($academicYearId === null || (! is_string($value) && ! $value instanceof DateTimeInterface)) {
                        return;
                    }

                    $academicYear = AcademicYear::find($academicYearId);

                    if (! $academicYear instanceof AcademicYear) {
                        return;
                    }

                    $enrolledOn = CarbonImmutable::parse($value)->startOfDay();

                    if ($enrolledOn->lessThan($academicYear->starts_on) || $enrolledOn->greaterThan($academicYear->ends_on)) {
                        $fail('The enrollment date must fall within the selected academic year.');
                    }
                },
            ],
        ];
    }
}
