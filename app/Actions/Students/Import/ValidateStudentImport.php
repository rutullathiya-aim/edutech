<?php

namespace App\Actions\Students\Import;

use App\Actions\Students\Import\Data\ParsedStudentImport;
use App\Actions\Students\Import\Data\StudentImportValidationResult;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumber;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ValidateStudentImport
{
    /**
     * Number of items per IN-clause chunk to stay safely under SQLite's bind limit while keeping query counts constant.
     */
    private const int LOOKUP_CHUNK_SIZE = 500;

    /**
     * Validate every parsed row for the given academic year using bulk-loaded lookups and return sanitized results.
     */
    public function handle(
        User $actor,
        AcademicYear $academicYear,
        ParsedStudentImport $parsed,
    ): StudentImportValidationResult {
        $schoolId = CurrentSchool::idOrFail();

        if ($academicYear->school_id !== $schoolId) {
            throw ValidationException::withMessages([
                'academic_year_id' => __('The selected academic year does not belong to the active school.'),
            ]);
        }

        Gate::forUser($actor)->authorize('import', Student::class);

        if ($academicYear->isClosed()) {
            throw ValidationException::withMessages([
                'academic_year_id' => __('Cannot import students into a closed academic year.'),
            ]);
        }

        $canViewSensitive = $actor->can('viewSensitive', Student::class);
        $today = CarbonImmutable::today();
        $maxAdmissionDate = $today->addYear()->startOfDay();
        $minDob = $today->subYears(20)->startOfDay();
        $maxDob = $today->subYears(2)->startOfDay();

        $allGrades = Grade::query()->ordered()->get();
        $activeGrades = $allGrades->filter(static fn (Grade $grade): bool => ! $grade->isArchived())->values();
        $archivedGrades = $allGrades->filter(static fn (Grade $grade): bool => $grade->isArchived())->values();

        $activeGradeLookup = StudentImportColumns::buildGradeLookup($activeGrades);
        $archivedGradeLookup = StudentImportColumns::buildGradeLookup($archivedGrades);
        $sectionsByGradeAndName = $this->loadYearSections($academicYear->id);

        $rowErrors = [];
        $parsedDrafts = [];
        $dateFormatRows = [
            'date_of_birth' => ['real' => [], 'text' => []],
            'admission_date' => ['real' => [], 'text' => []],
        ];
        $admissionOccurrences = [];
        $apaarOccurrences = [];
        $sectionRollOccurrences = [];
        $candidateMobiles = [];
        $candidateSectionIds = [];

        foreach ($parsed->rows as $rowData) {
            $rowNumber = $rowData['row_number'];
            $cells = $rowData['cells'];
            $errorsForRow = [];

            $addError = static function (string $column, string $message) use (&$errorsForRow): void {
                $errorsForRow[] = [
                    'column' => $column,
                    'message' => $message,
                ];
            };

            $admissionNo = StudentImportColumns::normalizeCellString($cells['admission_no'] ?? null);

            if ($admissionNo === null) {
                $addError('admission_no', __('Admission number is required.'));
            } elseif (mb_strlen($admissionNo, 'UTF-8') > 30) {
                $addError('admission_no', __('Admission number must not exceed 30 characters.'));
            } else {
                $admissionOccurrences[Str::lower($admissionNo)][] = $rowNumber;
            }

            $dob = null;
            $rawDob = $cells['date_of_birth'] ?? null;
            $dobKind = StudentImportColumns::classifyDateCellKind($rawDob);

            if ($dobKind !== null) {
                $dateFormatRows['date_of_birth'][$dobKind][] = $rowNumber;
            }

            if (StudentImportColumns::normalizeCellString($rawDob) === null && ! $rawDob instanceof \DateTimeInterface) {
                $addError('date_of_birth', __('Date of birth is required.'));
            } else {
                $dob = StudentImportColumns::parseImportDate($rawDob);

                if (! $dob instanceof CarbonImmutable) {
                    $addError('date_of_birth', __('Invalid date of birth format. Use dd/mm/yyyy.'));
                } elseif (! $dob->lessThan($today)) {
                    $addError('date_of_birth', __('Date of birth must be in the past.'));
                } elseif ($dob->greaterThan($maxDob) || $dob->lessThan($minDob)) {
                    $addError('date_of_birth', __('Student age must be between 2 and 20 years.'));
                }
            }

            $admissionDate = null;
            $rawAdmissionDate = $cells['admission_date'] ?? null;
            $admissionKind = StudentImportColumns::classifyDateCellKind($rawAdmissionDate);

            if ($admissionKind !== null) {
                $dateFormatRows['admission_date'][$admissionKind][] = $rowNumber;
            }

            if (StudentImportColumns::normalizeCellString($rawAdmissionDate) === null && ! $rawAdmissionDate instanceof \DateTimeInterface) {
                $addError('admission_date', __('Admission date is required.'));
            } else {
                $admissionDate = StudentImportColumns::parseImportDate($rawAdmissionDate);

                if (! $admissionDate instanceof CarbonImmutable) {
                    $addError('admission_date', __('Invalid admission date format. Use dd/mm/yyyy.'));
                } elseif ($admissionDate->greaterThan($maxAdmissionDate)) {
                    $addError('admission_date', __('Admission date cannot be more than one year in the future.'));
                } elseif ($dob instanceof CarbonImmutable && $admissionDate->lessThan($dob)) {
                    $addError('admission_date', __('Admission date must be on or after the date of birth.'));
                }
            }

            $name = StudentImportColumns::normalizeCellString($cells['name'] ?? null);

            if ($name === null) {
                $addError('name', __('Student name is required.'));
            } elseif (mb_strlen($name, 'UTF-8') > 150) {
                $addError('name', __('Student name must not exceed 150 characters.'));
            }

            $rawGender = StudentImportColumns::normalizeCellString($cells['gender'] ?? null);
            $gender = StudentImportColumns::parseGender($rawGender);

            if ($rawGender === null) {
                $addError('gender', __('Gender is required.'));
            } elseif ($gender === null) {
                $addError('gender', __('Invalid gender. Allowed values: Male, Female, Transgender.'));
            }

            $resolvedGrade = null;
            $rawGrade = StudentImportColumns::normalizeCellString($cells['grade'] ?? null);

            if ($rawGrade === null) {
                $addError('grade', __('Grade is required.'));
            } else {
                [$resolvedGrade, $gradeError] = $this->resolveGrade($rawGrade, $activeGradeLookup, $archivedGradeLookup);

                if ($gradeError !== null) {
                    $addError('grade', $gradeError);
                }
            }

            $resolvedSection = null;
            $rawSection = StudentImportColumns::normalizeCellString($cells['section'] ?? null);

            if ($rawSection === null) {
                $addError('section', __('Section is required.'));
            } elseif ($resolvedGrade instanceof Grade) {
                $sectionKey = StudentImportColumns::collapseKey($rawSection);
                $matchingSections = $sectionsByGradeAndName[$resolvedGrade->id][$sectionKey] ?? [];

                if (count($matchingSections) === 1) {
                    $resolvedSection = $matchingSections[0];
                } elseif (count($matchingSections) > 1) {
                    $addError('section', __('Section name matches multiple sections in this grade and academic year.'));
                } else {
                    $addError('section', __('Section does not exist for this grade in the selected academic year.'));
                }
            }

            $rollNumber = null;
            $rawRoll = StudentImportColumns::normalizeCellString($cells['roll_number'] ?? null);

            if ($rawRoll !== null) {
                if (ctype_digit($rawRoll)) {
                    $parsedRoll = (int) $rawRoll;

                    if ($parsedRoll >= 1 && $parsedRoll <= 65535) {
                        $rollNumber = $parsedRoll;

                        if ($resolvedSection instanceof Section) {
                            $sectionRollOccurrences["{$resolvedSection->id}|{$rollNumber}"][] = $rowNumber;
                            $candidateSectionIds[$resolvedSection->id] = true;
                        }
                    } else {
                        $addError('roll_number', __('Roll number must be between 1 and 65535.'));
                    }
                } else {
                    $addError('roll_number', __('Roll number must be a whole number.'));
                }
            }

            $guardiansForRow = $this->validateRowGuardians($cells, $addError);

            foreach ($guardiansForRow as $guardianSlot) {
                if ($guardianSlot['mobile'] !== null) {
                    $candidateMobiles[$guardianSlot['mobile']] = true;
                }
            }

            $addressLine = StudentImportColumns::normalizeCellString($cells['address_line'] ?? null);
            if ($addressLine !== null && mb_strlen($addressLine, 'UTF-8') > 255) {
                $addError('address_line', __('Address line must not exceed 255 characters.'));
            }

            $city = StudentImportColumns::normalizeCellString($cells['city'] ?? null);
            if ($city !== null && mb_strlen($city, 'UTF-8') > 100) {
                $addError('city', __('City must not exceed 100 characters.'));
            }

            $rawState = StudentImportColumns::normalizeCellString($cells['state'] ?? null);
            $state = StudentImportColumns::parseIndianState($rawState);
            if ($rawState !== null && $state === null) {
                $addError('state', __('Invalid Indian state or union territory name.'));
            }

            $pincode = StudentImportColumns::normalizeCellString($cells['pincode'] ?? null);
            if ($pincode !== null && preg_match('/^[1-9][0-9]{5}$/', $pincode) !== 1) {
                $addError('pincode', __('Pincode must be a valid 6-digit Indian postal code.'));
            }

            $rawBloodGroup = StudentImportColumns::normalizeCellString($cells['blood_group'] ?? null);
            $bloodGroup = StudentImportColumns::parseBloodGroup($rawBloodGroup);
            if ($rawBloodGroup !== null && $bloodGroup === null) {
                $addError('blood_group', __('Invalid blood group. Allowed values: A+, A-, B+, B-, AB+, AB-, O+, O-.'));
            }

            $motherTongue = StudentImportColumns::normalizeCellString($cells['mother_tongue'] ?? null);
            if ($motherTongue !== null && mb_strlen($motherTongue, 'UTF-8') > 50) {
                $addError('mother_tongue', __('Mother tongue must not exceed 50 characters.'));
            }

            $previousSchool = StudentImportColumns::normalizeCellString($cells['previous_school'] ?? null);
            if ($previousSchool !== null && mb_strlen($previousSchool, 'UTF-8') > 150) {
                $addError('previous_school', __('Previous school must not exceed 150 characters.'));
            }

            $socialCategory = null;
            $religion = null;
            $apaarId = null;
            $pen = null;
            $aadhaarLast4 = null;

            if ($canViewSensitive) {
                $rawCategory = StudentImportColumns::normalizeCellString($cells['social_category'] ?? null);
                $socialCategory = StudentImportColumns::parseSocialCategory($rawCategory);
                if ($rawCategory !== null && $socialCategory === null) {
                    $addError('social_category', __('Invalid social category. Allowed values: General, OBC, SC, ST.'));
                }

                $religion = StudentImportColumns::normalizeCellString($cells['religion'] ?? null);
                if ($religion !== null && mb_strlen($religion, 'UTF-8') > 30) {
                    $addError('religion', __('Religion must not exceed 30 characters.'));
                }

                $apaarId = StudentImportColumns::normalizeCellString($cells['apaar_id'] ?? null);
                if ($apaarId !== null) {
                    if (preg_match('/^[0-9]{12}$/', $apaarId) !== 1) {
                        $addError('apaar_id', __('Invalid APAAR ID. Enter exactly 12 digits.'));
                    } else {
                        $apaarOccurrences[$apaarId][] = $rowNumber;
                    }
                }

                $pen = StudentImportColumns::normalizeCellString($cells['pen'] ?? null);
                if ($pen !== null && preg_match('/^[0-9]{11}$/', $pen) !== 1) {
                    $addError('pen', __('Invalid PEN. Enter exactly 11 digits.'));
                }

                $aadhaarLast4 = StudentImportColumns::normalizeCellString($cells['aadhaar_last4'] ?? null);
                if ($aadhaarLast4 !== null && preg_match('/^[0-9]{4}$/', $aadhaarLast4) !== 1) {
                    $addError('aadhaar_last4', __('Invalid Aadhaar last 4 digits. Enter exactly 4 digits.'));
                }
            }

            $rowErrors[$rowNumber] = $errorsForRow;

            $parsedDrafts[] = [
                'row_number' => $rowNumber,
                'admission_no' => $admissionNo,
                'admission_key' => $admissionNo !== null ? Str::lower($admissionNo) : null,
                'apaar_id' => $apaarId,
                'section_id' => $resolvedSection?->id,
                'roll_number' => $rollNumber,
                'enrolled_on' => $admissionDate instanceof CarbonImmutable
                    ? $academicYear->resolveEnrollmentDate($admissionDate)->toDateString()
                    : '',
                'student' => [
                    'admission_no' => $admissionNo ?? '',
                    'admission_date' => $admissionDate?->toDateString() ?? '',
                    'name' => $name ?? '',
                    'date_of_birth' => $dob?->toDateString() ?? '',
                    'gender' => $gender instanceof Gender ? $gender->value : '',
                    'social_category' => $socialCategory?->value,
                    'religion' => $religion,
                    'nationality' => 'Indian',
                    'mother_tongue' => $motherTongue,
                    'blood_group' => $bloodGroup?->value,
                    'apaar_id' => $apaarId,
                    'pen' => $pen,
                    'aadhaar_last4' => $aadhaarLast4,
                    'address_line' => $addressLine,
                    'city' => $city,
                    'state' => $state?->value,
                    'pincode' => $pincode,
                    'previous_school' => $previousSchool,
                ],
                'guardians' => $guardiansForRow,
            ];
        }

        // Flag within-file uniqueness collisions on EVERY offending row (both first and duplicate rows).
        foreach ($admissionOccurrences as $rowsWithSameAdmission) {
            if (count($rowsWithSameAdmission) > 1) {
                foreach ($rowsWithSameAdmission as $dupRowNumber) {
                    $rowErrors[$dupRowNumber][] = [
                        'column' => 'admission_no',
                        'message' => __('Duplicate admission number within this file.'),
                    ];
                }
            }
        }

        foreach ($apaarOccurrences as $rowsWithSameApaar) {
            if (count($rowsWithSameApaar) > 1) {
                foreach ($rowsWithSameApaar as $dupRowNumber) {
                    $rowErrors[$dupRowNumber][] = [
                        'column' => 'apaar_id',
                        'message' => __('Duplicate APAAR ID within this file.'),
                    ];
                }
            }
        }

        foreach ($sectionRollOccurrences as $rowsWithSameRoll) {
            if (count($rowsWithSameRoll) > 1) {
                foreach ($rowsWithSameRoll as $dupRowNumber) {
                    $rowErrors[$dupRowNumber][] = [
                        'column' => 'roll_number',
                        'message' => __('Duplicate roll number for this section within this file.'),
                    ];
                }
            }
        }

        // Guard against US-locale Excel converting half a date column into real Date cells while ambiguous dd/mm (>12) remain text.
        foreach ($dateFormatRows as $dateColumn => $buckets) {
            if ($buckets['real'] === [] || $buckets['text'] === []) {
                continue;
            }

            $message = __('Dates in :column were read in two formats; your Excel may be set to US dates. Format the column as Text and re-type the dates as dd/mm/yyyy.', [
                'column' => $dateColumn,
            ]);

            foreach (array_merge($buckets['real'], $buckets['text']) as $affectedRowNumber) {
                $rowErrors[$affectedRowNumber][] = [
                    'column' => $dateColumn,
                    'message' => $message,
                ];
            }
        }

        // Bulk-load DB collisions & matching guardians chunked by 500 for constant query counts.
        $existingAdmissionKeys = $this->findExistingAdmissions(array_values(array_unique(array_filter(
            array_map(static fn (array $draft): ?string => $draft['admission_no'], $parsedDrafts),
            static fn (?string $val): bool => $val !== null,
        ))));

        $existingApaarIds = $canViewSensitive
            ? $this->findExistingApaarIds(array_values(array_unique(array_map('strval', array_keys($apaarOccurrences)))))
            : [];

        $existingRollKeys = $this->findExistingRollKeys(array_values(array_unique(array_map('strval', array_keys($candidateSectionIds)))));
        $existingGuardians = $this->findExistingGuardians(array_values(array_unique(array_map('strval', array_keys($candidateMobiles)))));

        foreach ($parsedDrafts as $draft) {
            $rowNumber = $draft['row_number'];

            if ($draft['admission_key'] !== null && isset($existingAdmissionKeys[$draft['admission_key']])) {
                $rowErrors[$rowNumber][] = [
                    'column' => 'admission_no',
                    'message' => __('Admission number already exists in this school.'),
                ];
            }

            if ($canViewSensitive && $draft['apaar_id'] !== null && isset($existingApaarIds[$draft['apaar_id']])) {
                $rowErrors[$rowNumber][] = [
                    'column' => 'apaar_id',
                    'message' => __('APAAR ID already exists in this school.'),
                ];
            }

            if ($draft['section_id'] !== null && $draft['roll_number'] !== null) {
                $rollKey = "{$draft['section_id']}|{$draft['roll_number']}";

                if (isset($existingRollKeys[$rollKey])) {
                    $rowErrors[$rowNumber][] = [
                        'column' => 'roll_number',
                        'message' => __('Roll number is already assigned in this section.'),
                    ];
                }
            }
        }

        $flattenedErrors = [];
        $validRowCount = 0;
        $invalidRowCount = 0;
        $preparedRows = [];

        foreach ($parsedDrafts as $draft) {
            $rowNumber = $draft['row_number'];
            $errorsForThisRow = $rowErrors[$rowNumber] ?? [];

            if ($errorsForThisRow !== []) {
                $invalidRowCount++;

                foreach ($errorsForThisRow as $error) {
                    $flattenedErrors[] = [
                        'row' => $rowNumber,
                        'column' => $error['column'],
                        'message' => $error['message'],
                    ];
                }

                continue;
            }

            $validRowCount++;

            if (! is_string($draft['section_id'])) {
                continue;
            }

            $resolvedGuardians = array_map(
                static function (array $guardian) use ($existingGuardians): array {
                    $existingId = null;

                    if ($guardian['mobile'] !== null) {
                        $lookupKey = "{$guardian['mobile']}|{$guardian['normalized_name_key']}";
                        $existingId = $existingGuardians[$lookupKey] ?? null;
                    }

                    return [
                        'existing_guardian_id' => $existingId,
                        'name' => $guardian['name'],
                        'normalized_name_key' => $guardian['normalized_name_key'],
                        'mobile' => $guardian['mobile'],
                        'relationship' => $guardian['relationship'],
                        'is_primary' => $guardian['is_primary'],
                    ];
                },
                $draft['guardians'],
            );

            $preparedRows[] = [
                'row_number' => $rowNumber,
                'student' => $draft['student'],
                'section_id' => $draft['section_id'],
                'roll_number' => $draft['roll_number'],
                'enrolled_on' => $draft['enrolled_on'],
                'guardians' => $resolvedGuardians,
            ];
        }

        return new StudentImportValidationResult(
            academicYearId: $academicYear->id,
            academicYearName: $academicYear->name,
            totalRows: count($parsedDrafts),
            validRowCount: $validRowCount,
            invalidRowCount: $invalidRowCount,
            errors: $flattenedErrors,
            preparedRows: $flattenedErrors === [] ? $preparedRows : [],
        );
    }

    /**
     * Load all sections for the academic year indexed by grade_id and normalized lowercase name.
     *
     * @return array<string, array<string, list<Section>>>
     */
    private function loadYearSections(string $academicYearId): array
    {
        $sections = Section::query()
            ->where('academic_year_id', $academicYearId)
            ->get();

        $grouped = [];

        foreach ($sections as $section) {
            $nameKey = StudentImportColumns::collapseKey($section->name);
            $grouped[$section->grade_id][$nameKey][] = $section;
        }

        return $grouped;
    }

    /**
     * Resolve a grade name or alias unambiguously against active grades, or detect archived matches.
     *
     * @param  array{exact_names: array<string, list<Grade>>, exact_shorts: array<string, list<Grade>>, aliases: array<string, list<Grade>>}  $activeLookup
     * @param  array{exact_names: array<string, list<Grade>>, exact_shorts: array<string, list<Grade>>, aliases: array<string, list<Grade>>}  $archivedLookup
     * @return array{0: ?Grade, 1: ?string}
     */
    private function resolveGrade(string $rawGrade, array $activeLookup, array $archivedLookup): array
    {
        $key = StudentImportColumns::collapseKey($rawGrade);

        foreach (['exact_names', 'exact_shorts', 'aliases'] as $bucket) {
            $matches = $activeLookup[$bucket][$key] ?? [];

            if (count($matches) === 1) {
                return [$matches[0], null];
            }

            if (count($matches) > 1) {
                return [null, __('Grade matches more than one active grade. Use the exact grade name.')];
            }
        }

        foreach (['exact_names', 'exact_shorts', 'aliases'] as $bucket) {
            if (($archivedLookup[$bucket][$key] ?? []) !== []) {
                return [null, __('Grade is archived and cannot accept new students.')];
            }
        }

        return [null, __('Grade does not match an active grade in this school.')];
    }

    /**
     * Validate father, mother, guardian slots and primary contact invariant on a single row.
     *
     * @param  array<string, mixed>  $cells
     * @param  callable(string, string): void  $addError
     * @return list<array{
     *     slot: string,
     *     name: string,
     *     normalized_name_key: string,
     *     mobile: string|null,
     *     relationship: string,
     *     is_primary: bool,
     * }>
     */
    private function validateRowGuardians(array $cells, callable $addError): array
    {
        $rawPrimary = StudentImportColumns::normalizeCellString($cells['primary_contact'] ?? null);
        $primarySlot = $rawPrimary !== null ? Str::lower($rawPrimary) : null;

        if ($primarySlot === null) {
            $addError('primary_contact', __('Primary contact is required.'));
        } elseif (! in_array($primarySlot, ['father', 'mother', 'guardian'], true)) {
            $addError('primary_contact', __('Invalid primary contact. Allowed values: father, mother, guardian.'));
            $primarySlot = null;
        }

        $rawGuardianRelationship = StudentImportColumns::normalizeCellString($cells['guardian_relationship'] ?? null);
        $parsedGuardianRelationship = GuardianRelationship::Guardian;

        if ($rawGuardianRelationship !== null) {
            $candidateRelationship = StudentImportColumns::parseGuardianRelationship($rawGuardianRelationship);

            if (! $candidateRelationship instanceof GuardianRelationship) {
                $addError('guardian_relationship', __('Invalid guardian relationship.'));
            } else {
                $parsedGuardianRelationship = $candidateRelationship;
            }
        }

        $slots = [
            'father' => [
                'name' => StudentImportColumns::normalizeCellString($cells['father_name'] ?? null),
                'raw_mobile' => StudentImportColumns::normalizeCellString($cells['father_mobile'] ?? null),
                'name_col' => 'father_name',
                'mobile_col' => 'father_mobile',
                'relationship' => GuardianRelationship::Father->value,
            ],
            'mother' => [
                'name' => StudentImportColumns::normalizeCellString($cells['mother_name'] ?? null),
                'raw_mobile' => StudentImportColumns::normalizeCellString($cells['mother_mobile'] ?? null),
                'name_col' => 'mother_name',
                'mobile_col' => 'mother_mobile',
                'relationship' => GuardianRelationship::Mother->value,
            ],
            'guardian' => [
                'name' => StudentImportColumns::normalizeCellString($cells['guardian_name'] ?? null),
                'raw_mobile' => StudentImportColumns::normalizeCellString($cells['guardian_mobile'] ?? null),
                'name_col' => 'guardian_name',
                'mobile_col' => 'guardian_mobile',
                'relationship' => $parsedGuardianRelationship->value,
            ],
        ];

        $validatedGuardians = [];
        $seenMobileNameKeys = [];

        foreach ($slots as $slotName => $slot) {
            $isPrimary = $primarySlot === $slotName;
            $hasName = $slot['name'] !== null;
            $hasMobile = $slot['raw_mobile'] !== null;

            if (! $hasName && ! $hasMobile && ! $isPrimary) {
                continue;
            }

            if (! $hasName) {
                $addError(
                    $slot['name_col'],
                    $isPrimary
                        ? __('Name is required for the selected primary contact.')
                        : __('Guardian name is required when a mobile number is provided.'),
                );
            } elseif (mb_strlen($slot['name'], 'UTF-8') > 150) {
                $addError($slot['name_col'], __('Guardian name must not exceed 150 characters.'));
            }

            $normalizedMobile = null;

            if ($hasMobile) {
                $normalizedMobile = MobileNumber::normalize($slot['raw_mobile']);

                if ($normalizedMobile === null || preg_match(MobileNumber::REGEX, $normalizedMobile) !== 1) {
                    $addError($slot['mobile_col'], __('Invalid Indian mobile number. Use a 10-digit number starting with 6 to 9.'));
                    $normalizedMobile = null;
                }
            } elseif ($isPrimary) {
                $addError($slot['mobile_col'], __('Primary contact must have a valid Indian mobile number.'));
            }

            if ($slot['name'] === null) {
                continue;
            }

            $normalizedNameKey = StudentImportColumns::collapseKey($slot['name']);

            if ($normalizedMobile !== null) {
                $pairKey = "{$normalizedMobile}|{$normalizedNameKey}";

                if (isset($seenMobileNameKeys[$pairKey])) {
                    $addError($slot['name_col'], __('Duplicate guardian mobile and name within the same student row.'));

                    continue;
                }

                $seenMobileNameKeys[$pairKey] = true;
            }

            $validatedGuardians[] = [
                'slot' => $slotName,
                'name' => $slot['name'],
                'normalized_name_key' => $normalizedNameKey,
                'mobile' => $normalizedMobile,
                'relationship' => $slot['relationship'],
                'is_primary' => $isPrimary,
            ];
        }

        return $validatedGuardians;
    }

    /**
     * Bulk check existing admission numbers for the active school in 500-item chunks.
     *
     * @param  list<string>  $admissionNumbers
     * @return array<string, true>
     */
    private function findExistingAdmissions(array $admissionNumbers): array
    {
        $found = [];

        foreach (array_chunk($admissionNumbers, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $matches = Student::query()
                ->whereIn('admission_no', $chunk)
                ->pluck('admission_no');

            foreach ($matches as $admissionNo) {
                $found[Str::lower((string) $admissionNo)] = true;
            }
        }

        return $found;
    }

    /**
     * Bulk check existing APAAR IDs for the active school in 500-item chunks.
     *
     * @param  list<string>  $apaarIds
     * @return array<string, true>
     */
    private function findExistingApaarIds(array $apaarIds): array
    {
        $found = [];

        foreach (array_chunk($apaarIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $matches = Student::query()
                ->whereIn('apaar_id', $chunk)
                ->pluck('apaar_id');

            foreach ($matches as $apaarId) {
                $found[(string) $apaarId] = true;
            }
        }

        return $found;
    }

    /**
     * Bulk check existing occupied section + roll_number pairs in 500-item chunks.
     *
     * @param  list<string>  $sectionIds
     * @return array<string, true>
     */
    private function findExistingRollKeys(array $sectionIds): array
    {
        $found = [];

        foreach (array_chunk($sectionIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            /** @var Collection<int, Enrollment> $enrollments */
            $enrollments = Enrollment::query()
                ->whereIn('section_id', $chunk)
                ->whereNotNull('roll_number')
                ->get(['section_id', 'roll_number']);

            foreach ($enrollments as $enrollment) {
                if ($enrollment->roll_number !== null) {
                    $found["{$enrollment->section_id}|{$enrollment->roll_number}"] = true;
                }
            }
        }

        return $found;
    }

    /**
     * Bulk load existing school guardians matching any candidate normalized mobile, indexed by mobile + collapsed lowercase name.
     *
     * @param  list<string>  $mobiles
     * @return array<string, string>
     */
    private function findExistingGuardians(array $mobiles): array
    {
        $found = [];

        foreach (array_chunk($mobiles, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            /** @var Collection<int, Guardian> $guardians */
            $guardians = Guardian::query()
                ->whereIn('mobile', $chunk)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'name', 'mobile']);

            foreach ($guardians as $guardian) {
                if ($guardian->mobile === null) {
                    continue;
                }

                $key = "{$guardian->mobile}|".StudentImportColumns::collapseKey($guardian->name);

                if (! isset($found[$key])) {
                    $found[$key] = $guardian->id;
                }
            }
        }

        return $found;
    }
}
