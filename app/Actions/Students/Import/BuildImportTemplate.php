<?php

namespace App\Actions\Students\Import;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\SocialCategory;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildImportTemplate
{
    /**
     * Generate a temporary XLSX student import template file and return its filesystem path.
     */
    public function buildTempFile(User $actor, ?string $academicYearId = null): string
    {
        CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('import', Student::class);

        $canViewSensitive = $actor->can('viewSensitive', Student::class);
        $targetYear = $this->resolveAcademicYear($academicYearId);
        $activeGrades = Grade::query()->active()->ordered()->get();

        $sectionsByGrade = $targetYear instanceof AcademicYear
            ? Section::query()
                ->where('academic_year_id', $targetYear->id)
                ->orderBy('name')
                ->get()
                ->groupBy('grade_id')
            : collect();

        $firstGrade = $activeGrades->first();
        $firstSection = $firstGrade instanceof Grade
            ? $sectionsByGrade->get($firstGrade->id)?->first()
            : null;

        $tempPath = StudentImportColumns::allocateTempXlsxPath('student-template');
        $writer = StudentImportColumns::openXlsxWriter($tempPath, 'Students');

        $headers = StudentImportColumns::templateHeaders($canViewSensitive);
        $writer->addRow(Row::fromValues($headers));

        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Instructions');

        foreach ($this->instructionRows($canViewSensitive, $headers, $targetYear, $activeGrades, $sectionsByGrade, $firstGrade, $firstSection) as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $tempPath;
    }

    /**
     * Stream the XLSX student import template as a browser download and delete the temporary file afterwards.
     */
    public function toDownloadResponse(User $actor, ?string $academicYearId = null): StreamedResponse
    {
        $school = CurrentSchool::getOrFail();
        $tempPath = $this->buildTempFile($actor, $academicYearId);
        $slug = Str::slug($school->name) ?: 'school';
        $filename = "{$slug}-student-import-template.xlsx";

        return response()->streamDownload(static function () use ($tempPath): void {
            try {
                readfile($tempPath);
            } finally {
                @unlink($tempPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Build one realistic Indian student example row aligned to the given template headers.
     *
     * @param  list<string>  $headers
     * @return list<string|int|null>
     */
    private function exampleRow(array $headers, ?Grade $firstGrade, ?Section $firstSection): array
    {
        $sampleValues = [
            'admission_no' => 'ADM-0001',
            'admission_date' => '01/04/2026',
            'name' => 'Aarav Sharma',
            'date_of_birth' => '05/06/2015',
            'gender' => 'Male',
            'grade' => $firstGrade instanceof Grade ? $firstGrade->name : 'Class 5',
            'section' => $firstSection instanceof Section ? $firstSection->name : 'A',
            'roll_number' => 1,
            'father_name' => 'Ramesh Sharma',
            'father_mobile' => '9876543210',
            'mother_name' => 'Sunita Sharma',
            'mother_mobile' => '9876543211',
            'guardian_name' => '',
            'guardian_mobile' => '',
            'guardian_relationship' => '',
            'primary_contact' => 'father',
            'address_line' => '12 MG Road',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380001',
            'blood_group' => 'O+',
            'mother_tongue' => 'Hindi',
            'previous_school' => 'Sunrise Public School',
            'social_category' => 'General',
            'religion' => 'Hindu',
            'apaar_id' => '123456789012',
            'pen' => '12345678901',
            'aadhaar_last4' => '4321',
        ];

        $row = [];

        foreach ($headers as $header) {
            $key = rtrim($header, '*');
            $row[] = $sampleValues[$key] ?? '';
        }

        return $row;
    }

    /**
     * Build guidance rows explaining formats, rules, this school's active grades/sections, and one example student.
     *
     * @param  list<string>  $headers
     * @param  \Illuminate\Database\Eloquent\Collection<int, Grade>  $activeGrades
     * @param  Collection<string, Collection<int, Section>>  $sectionsByGrade
     * @return list<array{0: string, 1: string}>
     */
    private function instructionRows(
        bool $canViewSensitive,
        array $headers,
        ?AcademicYear $targetYear,
        \Illuminate\Database\Eloquent\Collection $activeGrades,
        Collection $sectionsByGrade,
        ?Grade $firstGrade,
        ?Section $firstSection,
    ): array {
        $rows = [
            ['Topic', 'Instructions & Allowed Values'],
            ['Required columns (*)', 'Columns marked with * are mandatory: admission_no*, admission_date*, name*, date_of_birth*, gender*, grade*, section*, primary_contact*. Enter your first student on Row 2 of the Students sheet.'],
            ['Dates', 'Use dd/mm/yyyy format (for example, 05/06/2015 or 5/6/2015) or native Excel date cells. Do not mix formatted dates and text dates in the same column. US mm/dd/yyyy format is rejected.'],
            ['Primary contact', 'Allowed values: father, mother, guardian. The selected primary contact must have a valid 10-digit Indian mobile number (+91/0 prefix allowed).'],
            ['Siblings sharing guardians', 'Rows with the exact same guardian name and normalized mobile number are automatically linked to one guardian record. Parents sharing a mobile with different names stay separate records.'],
            ['Gender', implode(', ', array_map(static fn (Gender $case): string => $case->label(), Gender::cases()))],
            ['Blood group', implode(', ', array_map(static fn (BloodGroup $case): string => $case->value, BloodGroup::cases()))],
            ['Guardian relationship', implode(', ', array_map(static fn (GuardianRelationship $case): string => $case->value, GuardianRelationship::cases()))],
            ['Upload limits', 'Maximum 1,000 student rows per upload, maximum file size 5 MB, formats CSV UTF-8 or XLSX. All rows must be valid before any student is imported.'],
        ];

        if ($canViewSensitive) {
            $rows[] = [
                'Social category (restricted)',
                implode(', ', array_map(static fn (SocialCategory $case): string => $case->label(), SocialCategory::cases())),
            ];
            $rows[] = ['ID formats (restricted)', 'apaar_id: 12 digits; pen: 11 digits; aadhaar_last4: 4 digits.'];
        }

        $yearLabel = $targetYear instanceof AcademicYear ? $targetYear->name : 'Current academic year';
        $rows[] = ['School Grades & Sections', "Active grades and sections for {$yearLabel}:"];

        foreach ($activeGrades as $grade) {
            $sectionNames = $sectionsByGrade->get($grade->id)?->pluck('name')->implode(', ') ?: 'No sections configured for this year';
            $rows[] = [$grade->name, $sectionNames];
        }

        $rows[] = ['Example Student Row', 'Sample values (copy structure only onto Row 2+ of the Students sheet):'];
        $exampleValues = $this->exampleRow($headers, $firstGrade, $firstSection);

        foreach ($headers as $index => $header) {
            $sampleValue = $exampleValues[$index] ?? '';
            $rows[] = [$header, (string) $sampleValue];
        }

        return $rows;
    }

    /**
     * Resolve the target open academic year for displaying sections on the instructions sheet.
     */
    private function resolveAcademicYear(?string $academicYearId): ?AcademicYear
    {
        if ($academicYearId !== null) {
            return AcademicYear::query()->whereKey($academicYearId)->first();
        }

        return AcademicYear::query()
            ->whereNull('closed_at')
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->first();
    }
}
