<?php

use App\Actions\Students\Import\BuildImportErrorReport;
use App\Actions\Students\Import\BuildImportTemplate;
use App\Actions\Students\Import\ImportStudents;
use App\Actions\Students\Import\ParseStudentImportFile;
use App\Actions\Students\Import\StudentImportColumns;
use App\Actions\Students\Import\ValidateStudentImport;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\IndianState;
use App\Enums\MembershipStatus;
use App\Enums\SchoolPermission;
use App\Enums\SchoolRole;
use App\Enums\SocialCategory;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Support\SpreadsheetCell;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function (): void {
    test()->seed(PermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    CurrentSchool::forget();
    setPermissionsTeamId(null);

    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'imp_test_*') ?: [] as $tmpFile) {
        @unlink($tmpFile);
    }
});

/**
 * Create an active school member with the given role for import action tests.
 */
function createImportActor(School $school, SchoolRole $role): User
{
    $user = User::factory()->create();

    Membership::factory()->active()->for($user)->for($school)->create([
        'status' => MembershipStatus::Active,
    ]);

    setPermissionsTeamId($school->id);
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');

    return $user;
}

/**
 * Seed a school's open current academic year, active grades, and sections for import tests.
 *
 * @return array{year: AcademicYear, grade: Grade, sectionA: Section, sectionB: Section}
 */
function createImportSchoolStructure(School $school): array
{
    CurrentSchool::set($school);

    $year = AcademicYear::factory()->for($school)->current()->create([
        'name' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
    ]);

    $grade = Grade::factory()->for($school)->create([
        'name' => 'Class 5',
        'short_name' => '5',
        'sort_order' => 5,
    ]);

    $sectionA = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'A']);
    $sectionB = Section::factory()->for($school)->for($year)->for($grade)->create(['name' => 'B']);

    return compact('year', 'grade', 'sectionA', 'sectionB');
}

/**
 * Write an array of rows (first row headers) to a temporary XLSX or CSV file and return its path.
 *
 * @param  list<mixed>  $headers
 * @param  list<list<mixed>>  $rows
 */
function writeImportSpreadsheet(array $headers, array $rows, string $extension = 'xlsx'): string
{
    $base = tempnam(sys_get_temp_dir(), 'imp_test_');
    $path = $base.'.'.$extension;

    if (is_string($base) && file_exists($base)) {
        @unlink($base);
    }

    $writer = SimpleExcelWriter::create($path, $extension)->noHeaderRow();
    $writer->addRow($headers);

    foreach ($rows as $row) {
        $writer->addRow($row);
    }

    $writer->close();

    return $path;
}

/**
 * Build canonical template-shaped valid row values keyed by column name.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validImportRowMap(array $overrides = []): array
{
    return array_merge([
        'admission_no' => 'ADM-101',
        'admission_date' => '01/04/2026',
        'name' => 'Aarav Sharma',
        'date_of_birth' => '05/06/2015',
        'gender' => 'Male',
        'grade' => 'Class 5',
        'section' => 'A',
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
    ], $overrides);
}

/**
 * Convert a keyed row map into an indexed spreadsheet row ordered by the given headers.
 *
 * @param  list<string>  $headers
 * @param  array<string, mixed>  $map
 * @return list<mixed>
 */
function mapImportRow(array $headers, array $map): array
{
    return array_map(
        static fn (string $header): mixed => $map[rtrim($header, '*')] ?? '',
        $headers,
    );
}

test('import actions authorize Owner and Admin and reject Teacher, Accountant, Parent, and Student', function (
    SchoolRole $role,
    bool $allowed,
) {
    $school = School::factory()->active()->create();
    $actor = createImportActor($school, $role);
    $structure = createImportSchoolStructure($school);

    $headers = StudentImportColumns::templateHeaders(true);
    $path = writeImportSpreadsheet($headers, [mapImportRow($headers, validImportRowMap())]);

    if (! $allowed) {
        expect(fn () => (new BuildImportTemplate)->buildTempFile($actor, $structure['year']->id))
            ->toThrow(AuthorizationException::class);
        expect(fn () => (new ParseStudentImportFile)->handle($actor, $path, 'xlsx'))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(ImportStudents::class)->handle($actor, $structure['year']->id, $path, 'xlsx', true))
            ->toThrow(AuthorizationException::class);

        @unlink($path);

        return;
    }

    $templatePath = (new BuildImportTemplate)->buildTempFile($actor, $structure['year']->id);
    $parsed = (new ParseStudentImportFile)->handle($actor, $path, 'xlsx');
    $validation = (new ValidateStudentImport)->handle($actor, $structure['year'], $parsed);
    $result = app(ImportStudents::class)->handle($actor, $structure['year']->id, $path, 'xlsx', true);

    expect(file_exists($templatePath))->toBeTrue()
        ->and($validation->isValid())->toBeTrue()
        ->and($result->importedCount)->toBe(1);

    @unlink($templatePath);
    @unlink($path);
})->with([
    'owner' => [SchoolRole::Owner, true],
    'admin' => [SchoolRole::Admin, true],
    'teacher' => [SchoolRole::Teacher, false],
    'accountant' => [SchoolRole::Accountant, false],
    'parent' => [SchoolRole::Parent, false],
    'student' => [SchoolRole::Student, false],
]);

test('import template contains Students sheet, Instructions sheet with active grades and sections, and respects sensitive field permission', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    Grade::factory()->for($school)->archived()->create(['name' => 'Archived Grade 9']);

    $templatePath = (new BuildImportTemplate)->buildTempFile($admin, $structure['year']->id);

    $reader = SimpleExcelReader::create($templatePath, 'xlsx');
    $sheetNames = $reader->getSheetNames();
    $reader->close();

    $studentsRawRows = SimpleExcelReader::create($templatePath, 'xlsx')
        ->fromSheetName('Students')
        ->noHeaderRow()
        ->getRows()
        ->all();

    $instructionRows = SimpleExcelReader::create($templatePath, 'xlsx')
        ->fromSheetName('Instructions')
        ->noHeaderRow()
        ->getRows()
        ->all();

    $flatInstructions = json_encode($instructionRows);

    expect($sheetNames)->toBe(['Students', 'Instructions'])
        ->and($studentsRawRows)->toHaveCount(1) // headers only, no fake example student on Students sheet
        ->and($studentsRawRows[0])->toContain('admission_no*', 'primary_contact*', 'aadhaar_last4')
        ->and($flatInstructions)->toContain('Class 5', 'A, B', 'ADM-0001', 'Aarav Sharma')
        ->and($flatInstructions)->not->toContain('Archived Grade 9')
        ->and(str_starts_with($templatePath, StudentImportColumns::tempDirectory()))->toBeTrue();

    // Importing the downloaded template unchanged must fail cleanly with "no student rows".
    expect(fn () => (new ParseStudentImportFile)->handle($admin, $templatePath, 'xlsx'))
        ->toThrow(ValidationException::class, 'no student rows');

    @unlink($templatePath);

    // Temp cleanup prunes files older than 1 hour and keeps fresh files.
    $staleTmp = StudentImportColumns::allocateTempXlsxPath('stale-check');
    $freshTmp = StudentImportColumns::allocateTempXlsxPath('fresh-check');
    file_put_contents($staleTmp, 'stale');
    file_put_contents($freshTmp, 'fresh');
    touch($staleTmp, time() - 7200);
    touch($freshTmp, time() - 60);

    expect(StudentImportColumns::pruneExpiredTempFiles(3600))->toBeGreaterThanOrEqual(1)
        ->and(file_exists($staleTmp))->toBeFalse()
        ->and(file_exists($freshTmp))->toBeTrue();

    @unlink($freshTmp);

    // Clerk with StudentsImport permission only (without StudentsViewSensitive) omits sensitive columns.
    $limitedRole = Role::create([
        'school_id' => $school->id,
        'name' => 'import-clerk',
        'guard_name' => 'web',
    ]);
    $limitedRole->givePermissionTo(SchoolPermission::StudentsImport->value);

    $clerk = User::factory()->create();
    Membership::factory()->active()->for($clerk)->for($school)->create();
    setPermissionsTeamId($school->id);
    $clerk->assignRole($limitedRole);
    $clerk->unsetRelation('roles', 'permissions');

    $restrictedTemplatePath = (new BuildImportTemplate)->buildTempFile($clerk, $structure['year']->id);
    $restrictedRawRows = SimpleExcelReader::create($restrictedTemplatePath, 'xlsx')
        ->fromSheetName('Students')
        ->noHeaderRow()
        ->getRows()
        ->all();

    expect($restrictedRawRows)->toHaveCount(1)
        ->and($restrictedRawRows[0])
        ->not->toContain('social_category', 'religion', 'apaar_id', 'pen', 'aadhaar_last4');

    @unlink($restrictedTemplatePath);
});

test('valid XLSX import creates students, merges sibling guardians, keeps shared-phone parents separate, reuses DB guardians, normalizes mobiles, and writes one summary audit log', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    $existingFather = Guardian::factory()->for($school)->create([
        'name' => 'Mahesh Iyer',
        'mobile' => '+919822223333',
    ]);
    $existingSharedPhoneOther = Guardian::factory()->for($school)->create([
        'name' => 'Deepak Nair',
        'mobile' => '+919811112222',
    ]);

    $headers = [
        '  Admission_No* ',
        'ADMISSION_DATE*',
        'Name*',
        'date_of_birth*',
        'gender*',
        'grade*',
        'section*',
        'roll_number',
        'father_name',
        'father_mobile',
        'mother_name',
        'mother_mobile',
        'guardian_name',
        'guardian_mobile',
        'guardian_relationship',
        'primary_contact*',
        'blood_group',
        'state',
        'social_category',
        'religion',
        'apaar_id',
        'pen',
        'aadhaar_last4',
        'school_id',
        'id',
        'errors',
    ];

    $otherSchool = School::factory()->create();

    $rows = [
        // Row 2: Sibling 1 with Mahesh Iyer (reused from DB) and Kavita Iyer (mother sharing phone)
        [
            'IMP-001',
            '01/04/2026',
            'Ananya Iyer',
            '05/06/2015',
            'Female',
            '5',
            'a',
            '1',
            'mahesh iyer',
            '9822223333',
            'Kavita Iyer',
            '+91 98222 23333',
            '',
            '',
            '',
            'father',
            'o+',
            'gujarat',
            'General',
            'Hindu',
            '100000000001',
            '10000000001',
            '1111',
            $otherSchool->id,
            'fake-id-1',
            'old error',
        ],
        // Row 3: Sibling 2 sharing both Mahesh Iyer and Kavita Iyer
        [
            'IMP-002',
            '01-04-2026',
            'Rohan Iyer',
            '10.08.2016',
            'Male',
            'V',
            'A',
            '2',
            'Mahesh Iyer',
            '09822223333',
            'kavita iyer',
            '919822223333',
            '',
            '',
            '',
            'Mother',
            'B+',
            'Maharashtra',
            'OBC',
            'Hindu',
            '100000000002',
            '10000000002',
            '2222',
            $otherSchool->id,
            'fake-id-2',
            '',
        ],
        // Row 4: Mother shares Deepak Nair's phone with a different name -> new guardian record; 1-digit text date supported!
        [
            'IMP-003',
            '2/4/2026',
            'Meera Nair',
            '5/6/2015',
            'Female',
            'Std 5',
            'B',
            '',
            '',
            '',
            'Lakshmi Nair',
            '9811112222',
            'Uncle Raj',
            '9833334444',
            'Other',
            'mother',
            '',
            '',
            'SC',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ],
    ];

    $xlsxPath = writeImportSpreadsheet($headers, $rows, 'xlsx');

    $beforeActivityCount = Activity::query()->count();

    $result = app(ImportStudents::class)->handle(
        actor: $admin,
        academicYearId: $structure['year']->id,
        filePath: $xlsxPath,
        extension: 'xlsx',
        consentConfirmed: true,
    );

    expect($result->importedCount)->toBe(3)
        ->and($result->guardiansCreatedCount)->toBe(3) // Kavita Iyer, Lakshmi Nair, Uncle Raj
        ->and($result->guardiansReusedCount)->toBe(3); // Mahesh x2 + Kavita x1

    expect(Student::query()->count())->toBe(3)
        ->and(Guardian::query()->count())->toBe(5) // 2 seeded + 3 newly created
        ->and(StudentGuardian::query()->count())->toBe(6)
        ->and(Enrollment::query()->count())->toBe(3);

    $ananya = Student::query()->where('admission_no', 'IMP-001')->firstOrFail();
    $rohan = Student::query()->where('admission_no', 'IMP-002')->firstOrFail();
    $meera = Student::query()->where('admission_no', 'IMP-003')->firstOrFail();

    expect($ananya->school_id)->toBe($school->id)
        ->and($ananya->id)->not->toBe('fake-id-1')
        ->and($ananya->date_of_birth->toDateString())->toBe('2015-06-05')
        ->and($ananya->gender)->toBe(Gender::Female)
        ->and($ananya->blood_group)->toBe(BloodGroup::OPos)
        ->and($ananya->state)->toBe(IndianState::Gujarat)
        ->and($ananya->social_category)->toBe(SocialCategory::General)
        ->and($ananya->consent_recorded_by)->toBe($admin->id)
        ->and($ananya->consent_recorded_at)->not->toBeNull()
        ->and($ananya->status)->toBe(StudentStatus::Active);

    // Single-digit text dates (5/6/2015 and 2/4/2026) resolve cleanly as dd/mm/yyyy.
    expect($rohan->date_of_birth->toDateString())->toBe('2016-08-10')
        ->and($meera->date_of_birth->toDateString())->toBe('2015-06-05')
        ->and($meera->admission_date->toDateString())->toBe('2026-04-02');

    // Siblings share the exact same father (existing DB record) and mother (newly created once).
    $ananyaFather = $ananya->studentGuardians()->where('relationship', GuardianRelationship::Father)->firstOrFail();
    $rohanFather = $rohan->studentGuardians()->where('relationship', GuardianRelationship::Father)->firstOrFail();
    $ananyaMother = $ananya->studentGuardians()->where('relationship', GuardianRelationship::Mother)->firstOrFail();
    $rohanMother = $rohan->studentGuardians()->where('relationship', GuardianRelationship::Mother)->firstOrFail();

    expect($ananyaFather->guardian_id)->toBe($existingFather->id)
        ->and($rohanFather->guardian_id)->toBe($existingFather->id)
        ->and($ananyaFather->is_primary)->toBeTrue()
        ->and($rohanMother->is_primary)->toBeTrue()
        ->and($ananyaMother->guardian_id)->toBe($rohanMother->guardian_id);

    // Mother sharing phone with existing guardian of different name creates a distinct guardian.
    $meeraMother = $meera->studentGuardians()->where('relationship', GuardianRelationship::Mother)->firstOrFail();
    $meeraGuardian = $meera->studentGuardians()->where('relationship', GuardianRelationship::Other)->firstOrFail();

    expect($meeraMother->guardian_id)->not->toBe($existingSharedPhoneOther->id)
        ->and($meeraMother->is_primary)->toBeTrue()
        ->and($meeraGuardian->guardian_id)->not->toBe($meeraMother->guardian_id);

    // Only ONE summary audit log entry created; no individual student names or sensitive fields recorded.
    $newLogs = Activity::query()->skip($beforeActivityCount)->take(10)->get();

    expect($newLogs)->toHaveCount(1)
        ->and($newLogs->first()->log_name)->toBe('student_import')
        ->and($newLogs->first()->description)->toBe('Imported 3 students into 2026-27')
        ->and($newLogs->first()->properties->get('imported_count'))->toBe(3)
        ->and(json_encode($newLogs->first()->properties))->not->toContain('Ananya', 'Rohan', 'Meera', 'Hindu', '100000000001')
        ->and(app(ActivityLogStatus::class)->disabled())->toBeFalse();

    @unlink($xlsxPath);
});

test('valid CSV import works and resolves next-year February admissions to academic year start date', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    CurrentSchool::set($school);

    $nextYear = AcademicYear::factory()->for($school)->create([
        'name' => '2027-28',
        'starts_on' => '2027-04-01',
        'ends_on' => '2028-03-31',
        'is_current' => false,
    ]);
    $grade = Grade::factory()->for($school)->create(['name' => 'Class 5', 'sort_order' => 5]);
    $section = Section::factory()->for($school)->for($nextYear)->for($grade)->create(['name' => 'A']);

    $headers = StudentImportColumns::templateHeaders(false);
    $csvPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'CSV-001',
            'admission_date' => '15/02/2027',
            'date_of_birth' => '05.06.2015',
            'grade' => '5th',
            'section' => 'a',
        ])),
    ], 'csv');

    $result = app(ImportStudents::class)->handle(
        actor: $admin,
        academicYearId: $nextYear->id,
        filePath: $csvPath,
        extension: 'csv',
        consentConfirmed: true,
    );

    $enrollment = Enrollment::query()->firstOrFail();

    expect($result->importedCount)->toBe(1)
        ->and($enrollment->section_id)->toBe($section->id)
        ->and($enrollment->enrolled_on->toDateString())->toBe('2027-04-01');

    @unlink($csvPath);
});

test('validator collects all errors across all rows, flags within-file duplicates on both rows, never leaks cell values, and rolls back on any error', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    // Ambiguous grade + archived grade + existing DB student & occupied roll 1
    Grade::factory()->for($school)->create(['name' => 'Grade 5', 'sort_order' => 6]);
    Grade::factory()->for($school)->archived()->create(['name' => 'Class 8', 'sort_order' => 8]);

    $existingStudent = Student::factory()->for($school)->create([
        'admission_no' => 'EXIST-01',
        'apaar_id' => '999988887777',
    ]);
    Enrollment::factory()->for($school)->for($existingStudent)->forSection($structure['sectionA'])->create([
        'roll_number' => 1,
        'enrolled_on' => '2026-04-01',
    ]);

    $headers = StudentImportColumns::templateHeaders(true);

    $rows = [
        // Row 2: Shares duplicate within-file admission_no & roll_number with Row 3, plus US date & primary without mobile & formula attack
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'DUP-100',
            'date_of_birth' => '06/15/2015', // mm/dd must fail!
            'grade' => 'Class 5',
            'section' => 'A',
            'roll_number' => 9,
            'name' => '=cmd|CanaryFormula',
            'father_mobile' => '',
            'primary_contact' => 'father',
            'aadhaar_last4' => 'CanaryBad99',
        ])),
        // Row 3: Duplicates DUP-100 & roll 9, plus ambiguous grade "5" and invalid gender
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'dup-100',
            'date_of_birth' => '05/06/2015',
            'gender' => 'CanaryGender',
            'grade' => '5', // matches both Class 5 and Grade 5 -> ambiguous!
            'section' => 'A',
            'roll_number' => '',
            'apaar_id' => '999988887777', // exists in DB
        ])),
        // Row 4: Collides with DB admission & roll number, archived grade, impossible date
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'EXIST-01',
            'date_of_birth' => '31/02/2015',
            'grade' => 'Class 5',
            'section' => 'A',
            'roll_number' => 1, // occupied in DB!
        ])),
        // Row 5: Archived grade
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'OK-NEW-5',
            'grade' => 'Class 8',
            'section' => 'A',
            'roll_number' => 9,
        ])),
        // Row 6: Same roll_number as Row 2 in Class 5 A? No, different grade failed, so test direct duplicate section+roll in rows 6 & 7!
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'OK-NEW-6',
            'grade' => 'Class 5',
            'section' => 'A',
            'roll_number' => 9, // duplicate within file with Row 2!
        ])),
    ];

    $path = writeImportSpreadsheet($headers, $rows, 'xlsx');
    $parsed = (new ParseStudentImportFile)->handle($admin, $path, 'xlsx');
    $validation = (new ValidateStudentImport)->handle($admin, $structure['year'], $parsed);

    expect($validation->isValid())->toBeFalse()
        ->and($validation->hasErrors())->toBeTrue()
        ->and($validation->totalRows)->toBe(5)
        ->and($validation->invalidRowCount)->toBe(5);

    // Within-file duplicate admission_no must flag BOTH Row 2 and Row 3.
    $dupAdmissionRows = collect($validation->errors)
        ->where('column', 'admission_no')
        ->pluck('row')
        ->all();
    expect($dupAdmissionRows)->toContain(2, 3, 4);

    // Within-file duplicate roll_number must flag BOTH Row 2 and Row 6, plus DB collision Row 4.
    $dupRollRows = collect($validation->errors)
        ->where('column', 'roll_number')
        ->pluck('row')
        ->all();
    expect($dupRollRows)->toContain(2, 4, 6);

    // Error messages must NEVER echo raw cell values.
    $serializedErrors = json_encode($validation->errors);
    expect($serializedErrors)
        ->not->toContain('CanaryFormula', 'CanaryBad99', 'CanaryGender', 'DUP-100', '999988887777', '06/15/2015', 'EXIST-01');

    // Confirming import with invalid rows leaves zero newly created records.
    expect(fn () => app(ImportStudents::class)->handle($admin, $structure['year']->id, $path, 'xlsx', true))
        ->toThrow(ValidationException::class);

    expect(Student::query()->count())->toBe(1) // only the seeded existingStudent
        ->and(Enrollment::query()->count())->toBe(1);

    // Error report generation via CSV escapes all dangerous formula prefixes without relying on keepFormulas().
    $formulaPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'name' => '=1+1',
            'admission_no' => '+2+2',
            'address_line' => '-3+3',
            'father_name' => '@SUM(1,2)',
            'city' => "\t=CMD",
            'mother_name' => "\r=CMD",
            'aadhaar_last4' => 'bad',
        ])),
    ], 'csv');

    $formulaParsed = (new ParseStudentImportFile)->handle($admin, $formulaPath, 'csv');
    $formulaValidation = (new ValidateStudentImport)->handle($admin, $structure['year'], $formulaParsed);
    $reportPath = (new BuildImportErrorReport)->buildTempFile($admin, $formulaParsed, $formulaValidation);

    $reportRows = SimpleExcelReader::create($reportPath, 'xlsx')
        ->fromSheetName('Failed rows')
        ->getRows()
        ->all();

    expect($reportRows)->toHaveCount(1)
        ->and($reportRows[0]['name*'])->toBe("'=1+1")
        ->and($reportRows[0]['admission_no*'])->toBe("'+2+2")
        ->and($reportRows[0]['address_line'])->toBe("'-3+3")
        ->and($reportRows[0]['father_name'])->toBe("'@SUM(1,2)")
        ->and($reportRows[0]['city'])->toBe("'\t=CMD")
        ->and($reportRows[0]['mother_name'])->toStartWith("'")
        ->and($reportRows[0]['errors'])->not->toContain('bad')
        ->and(SpreadsheetCell::escapeFormula('=1+1'))->toBe("'=1+1")
        ->and(SpreadsheetCell::escapeFormula('+1+1'))->toBe("'+1+1")
        ->and(SpreadsheetCell::escapeFormula('-1+1'))->toBe("'-1+1")
        ->and(SpreadsheetCell::escapeFormula('@SUM(1)'))->toBe("'@SUM(1)")
        ->and(SpreadsheetCell::escapeFormula("\t=1"))->toBe("'\t=1")
        ->and(SpreadsheetCell::escapeFormula("\r=1"))->toBe("'\r=1")
        ->and(SpreadsheetCell::escapeFormula('Aarav Sharma'))->toBe('Aarav Sharma')
        ->and(SpreadsheetCell::escapeFormula(42))->toBe(42);

    @unlink($path);
    @unlink($formulaPath);
    @unlink($reportPath);
});

test('non-sensitive importer ignores sensitive columns cleanly on import and error export', function () {
    $school = School::factory()->active()->create();
    $structure = createImportSchoolStructure($school);

    $limitedRole = Role::create([
        'school_id' => $school->id,
        'name' => 'clerk-no-sensitive',
        'guard_name' => 'web',
    ]);
    $limitedRole->givePermissionTo(SchoolPermission::StudentsImport->value);

    $clerk = User::factory()->create();
    Membership::factory()->active()->for($clerk)->for($school)->create();
    setPermissionsTeamId($school->id);
    $clerk->assignRole($limitedRole);
    $clerk->unsetRelation('roles', 'permissions');

    $headers = StudentImportColumns::templateHeaders(true);

    $validPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'NS-001',
            'social_category' => 'OBC',
            'religion' => 'Sikh',
            'apaar_id' => '999999999999',
            'pen' => '88888888888',
            'aadhaar_last4' => '4567',
        ])),
    ]);

    app(ImportStudents::class)->handle($clerk, $structure['year']->id, $validPath, 'xlsx', true);

    $student = Student::query()->where('admission_no', 'NS-001')->firstOrFail();

    expect($student->social_category)->toBeNull()
        ->and($student->religion)->toBeNull()
        ->and($student->apaar_id)->toBeNull()
        ->and($student->pen)->toBeNull()
        ->and($student->aadhaar_last4)->toBeNull();

    // Also error report for non-sensitive actor strips sensitive columns completely.
    $badPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'NS-002',
            'date_of_birth' => 'invalid-date',
            'religion' => 'SecretReligion',
            'aadhaar_last4' => '9999',
        ])),
    ]);

    $parsedBad = (new ParseStudentImportFile)->handle($clerk, $badPath, 'xlsx');
    $validatedBad = (new ValidateStudentImport)->handle($clerk, $structure['year'], $parsedBad);
    $reportPath = (new BuildImportErrorReport)->buildTempFile($clerk, $parsedBad, $validatedBad);

    $reportRows = SimpleExcelReader::create($reportPath, 'xlsx')->getRows()->all();
    expect(array_keys($reportRows[0]))
        ->not->toContain('social_category', 'religion', 'apaar_id', 'pen', 'aadhaar_last4')
        ->and(json_encode($reportRows))->not->toContain('SecretReligion');

    @unlink($validPath);
    @unlink($badPath);
    @unlink($reportPath);
});

test('parser refuses files over 1000 rows, over 5 MB, wrong extensions, empty files, and corrupt files', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    createImportSchoolStructure($school);

    // > 1,000 rows in CSV refused cleanly
    $csv1001Path = tempnam(sys_get_temp_dir(), 'imp_test_').'.csv';
    $handle = fopen($csv1001Path, 'wb');
    fwrite($handle, "admission_no*,admission_date*,name*,date_of_birth*,gender*,grade*,section*,father_name,father_mobile,primary_contact*\n");
    for ($i = 1; $i <= 1001; $i++) {
        fwrite($handle, "R-{$i},01/04/2026,Student {$i},05/06/2015,Male,Class 5,A,Father {$i},9876543210,father\n");
    }
    fclose($handle);

    expect(fn () => (new ParseStudentImportFile)->handle($admin, $csv1001Path, 'csv'))
        ->toThrow(ValidationException::class);
    @unlink($csv1001Path);

    // > 5 MB file refused
    $bigPath = tempnam(sys_get_temp_dir(), 'imp_test_').'.csv';
    $bigHandle = fopen($bigPath, 'wb');
    fseek($bigHandle, (5 * 1024 * 1024) + 10);
    fwrite($bigHandle, 'x');
    fclose($bigHandle);

    expect(fn () => (new ParseStudentImportFile)->handle($admin, $bigPath, 'csv'))
        ->toThrow(ValidationException::class);
    @unlink($bigPath);

    // Corrupt XLSX refused
    $corruptPath = tempnam(sys_get_temp_dir(), 'imp_test_').'.xlsx';
    file_put_contents($corruptPath, 'this-is-not-a-zip-xlsx');

    expect(fn () => (new ParseStudentImportFile)->handle($admin, $corruptPath, 'xlsx'))
        ->toThrow(ValidationException::class);
    @unlink($corruptPath);

    // Unsupported extension refused even if valid file content
    $txtPath = writeImportSpreadsheet(['admission_no*', 'name*'], [['A1', 'Name']], 'csv');
    expect(fn () => (new ParseStudentImportFile)->handle($admin, $txtPath, 'xls'))
        ->toThrow(ValidationException::class);
    @unlink($txtPath);
});

test('validator executes constant query count for 50 vs 500 rows and 1000-row import completes within budget', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    // Prime permission cache before counting queries.
    $admin->can('import', Student::class);
    $admin->can('viewSensitive', Student::class);

    $headers = StudentImportColumns::templateHeaders(true);

    $buildCsv = static function (int $count) use ($headers): string {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $mobile = '9'.str_pad((string) (800000000 + $i), 9, '0', STR_PAD_LEFT);
            $apaar = str_pad((string) (100000000000 + $i), 12, '0', STR_PAD_LEFT);
            $rows[] = mapImportRow($headers, validImportRowMap([
                'admission_no' => sprintf('PERF-%04d', $i),
                'name' => "Student {$i}",
                'roll_number' => $i,
                'section' => $i <= 500 ? 'A' : 'B',
                'father_name' => "Father {$i}",
                'father_mobile' => $mobile,
                'mother_name' => '',
                'mother_mobile' => '',
                'apaar_id' => $apaar,
            ]));
        }

        return writeImportSpreadsheet($headers, $rows, 'csv');
    };

    $path50 = $buildCsv(50);
    $path500 = $buildCsv(500);
    $parser = new ParseStudentImportFile;
    $validator = new ValidateStudentImport;

    $parsed50 = $parser->handle($admin, $path50, 'csv');
    $parsed500 = $parser->handle($admin, $path500, 'csv');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $res50 = $validator->handle($admin, $structure['year'], $parsed50);
    $queries50 = count(DB::getQueryLog());
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $res500 = $validator->handle($admin, $structure['year'], $parsed500);
    $queries500 = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($res50->isValid())->toBeTrue()
        ->and($res500->isValid())->toBeTrue()
        ->and($queries500)->toBe($queries50);

    @unlink($path50);
    @unlink($path500);

    // Full 1,000-row import end-to-end check
    $path1000 = $buildCsv(1000);
    $startedAt = microtime(true);

    $result1000 = app(ImportStudents::class)->handle(
        actor: $admin,
        academicYearId: $structure['year']->id,
        filePath: $path1000,
        extension: 'csv',
        consentConfirmed: true,
    );

    $elapsedSeconds = microtime(true) - $startedAt;

    expect($result1000->importedCount)->toBe(1000)
        ->and($elapsedSeconds)->toBeLessThan(15.0)
        ->and(Student::query()->count())->toBe(1000)
        ->and(Enrollment::query()->count())->toBe(1000);

    @unlink($path1000);
});

test('rate limiter refuses the 11th confirmed import within one hour per school while isolating other schools', function () {
    $schoolA = School::factory()->active()->create();
    $schoolB = School::factory()->active()->create();

    $adminA = createImportActor($schoolA, SchoolRole::Admin);
    $structA = createImportSchoolStructure($schoolA);

    $adminB = createImportActor($schoolB, SchoolRole::Admin);
    $structB = createImportSchoolStructure($schoolB);

    RateLimiter::clear(ImportStudents::rateLimitKey($schoolA->id));
    RateLimiter::clear(ImportStudents::rateLimitKey($schoolB->id));

    $headers = StudentImportColumns::templateHeaders(false);
    $importer = app(ImportStudents::class);

    CurrentSchool::set($schoolA);
    setPermissionsTeamId($schoolA->id);

    for ($i = 1; $i <= 10; $i++) {
        $path = writeImportSpreadsheet($headers, [
            mapImportRow($headers, validImportRowMap([
                'admission_no' => "RL-A-{$i}",
                'roll_number' => $i,
            ])),
        ], 'csv');

        $importer->handle($adminA, $structA['year']->id, $path, 'csv', true);
        @unlink($path);
    }

    $path11 = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'RL-A-11',
            'roll_number' => 11,
        ])),
    ], 'csv');

    expect(fn () => $importer->handle($adminA, $structA['year']->id, $path11, 'csv', true))
        ->toThrow(ValidationException::class);
    @unlink($path11);

    // Other school is unaffected by school A's quota.
    CurrentSchool::set($schoolB);
    setPermissionsTeamId($schoolB->id);
    $pathB = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'RL-B-1',
            'roll_number' => 1,
        ])),
    ], 'csv');

    $resB = $importer->handle($adminB, $structB['year']->id, $pathB, 'csv', true);
    expect($resB->importedCount)->toBe(1);
    @unlink($pathB);
});

test('import detects database changes made after dry run and imports zero records on failure', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    $headers = StudentImportColumns::templateHeaders(false);
    $path = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap(['admission_no' => 'RACE-01', 'roll_number' => 5])),
        mapImportRow($headers, validImportRowMap(['admission_no' => 'RACE-02', 'roll_number' => 6])),
    ], 'csv');

    $parsed = (new ParseStudentImportFile)->handle($admin, $path, 'csv');
    $dryRun = (new ValidateStudentImport)->handle($admin, $structure['year'], $parsed);
    expect($dryRun->isValid())->toBeTrue();

    // Another user claims RACE-02 before confirm executes.
    Student::factory()->for($school)->create(['admission_no' => 'RACE-02']);

    expect(fn () => app(ImportStudents::class)->handle($admin, $structure['year']->id, $path, 'csv', true))
        ->toThrow(ValidationException::class);

    // Neither RACE-01 nor a duplicate RACE-02 was inserted.
    expect(Student::query()->where('admission_no', 'RACE-01')->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(1);

    @unlink($path);
});

test('XLSX formula cells use computed value instead of formula string and uncalculated formulas fail validation cleanly', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    $headers = StudentImportColumns::templateHeaders(false);

    // 1. Uncalculated formula cell written into name column must fail as missing/required name, never saving '=...'.
    $uncalculatedPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'FRM-001',
            'name' => '=B2&" "&C2',
        ])),
    ], 'xlsx');

    $parsedUncalculated = (new ParseStudentImportFile)->handle($admin, $uncalculatedPath, 'xlsx');
    $validationUncalculated = (new ValidateStudentImport)->handle($admin, $structure['year'], $parsedUncalculated);

    expect($validationUncalculated->isValid())->toBeFalse()
        ->and($validationUncalculated->errors)->toHaveCount(1)
        ->and($validationUncalculated->errors[0]['column'])->toBe('name')
        ->and($validationUncalculated->errors[0]['message'])->toContain('required');

    expect(fn () => app(ImportStudents::class)->handle($admin, $structure['year']->id, $uncalculatedPath, 'xlsx', true))
        ->toThrow(ValidationException::class);

    expect(Student::query()->count())->toBe(0);
    @unlink($uncalculatedPath);

    // 2. Calculated formula cell uses its evaluated cached value (<v>) when Excel saved a computed result.
    $base = tempnam(sys_get_temp_dir(), 'imp_test_');
    $calculatedPath = $base.'.xlsx';
    if (is_string($base) && file_exists($base)) {
        @unlink($base);
    }

    $writer = SimpleExcelWriter::create($calculatedPath, 'xlsx')->noHeaderRow();
    $writer->addRow($headers);
    $writer->addRow(mapImportRow($headers, validImportRowMap([
        'admission_no' => 'FRM-002',
        'name' => '=B2&" "&C2',
    ])));
    $writer->close();

    // Inject Excel's cached formula result (t="str" + <v>Kavya Deshmukh</v>) into the worksheet XML (since OpenSpout writes <f> without <v>).
    $zip = new ZipArchive;
    expect($zip->open($calculatedPath))->toBeTrue();
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $updatedXml = preg_replace(
        '/<c r="([A-Z0-9]+)" s="([0-9]+)"><f>([^<]+)<\/f><\/c>/',
        '<c r="$1" s="$2" t="str"><f>$3</f><v>Kavya Deshmukh</v></c>',
        $sheetXml,
        1,
        $replacedCount,
    );
    expect($replacedCount)->toBe(1)
        ->and($updatedXml)->toBeString();
    $zip->addFromString('xl/worksheets/sheet1.xml', (string) $updatedXml);
    $zip->close();

    app(ImportStudents::class)->handle($admin, $structure['year']->id, $calculatedPath, 'xlsx', true);

    $student = Student::query()->where('admission_no', 'FRM-002')->firstOrFail();
    expect($student->name)->toBe('Kavya Deshmukh')
        ->and(str_starts_with($student->name, '='))->toBeFalse();

    @unlink($calculatedPath);
});

test('validator accepts uniform Excel real dates and rejects columns mixing real date cells and text dates', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    $structure = createImportSchoolStructure($school);

    $headers = StudentImportColumns::templateHeaders(false);

    // 1. Uniform real Excel dates (DateTimeImmutable + Excel serial 42160) succeed.
    $uniformRealPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'DATE-R1',
            'admission_date' => new DateTimeImmutable('2026-04-01'),
            'date_of_birth' => new DateTimeImmutable('2015-06-05'),
            'roll_number' => 1,
        ])),
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'DATE-R2',
            'admission_date' => new DateTimeImmutable('2026-04-02'),
            'date_of_birth' => 42160, // Excel serial for 2015-06-05
            'roll_number' => 2,
        ])),
    ], 'xlsx');

    app(ImportStudents::class)->handle($admin, $structure['year']->id, $uniformRealPath, 'xlsx', true);
    expect(Student::query()->where('admission_no', 'DATE-R2')->firstOrFail()->date_of_birth->toDateString())
        ->toBe('2015-06-05');
    @unlink($uniformRealPath);

    // 2. Mixed real Excel Date cell + dd/mm/yyyy text string in date_of_birth rejects the column with US-date warning.
    $mixedDobPath = writeImportSpreadsheet($headers, [
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'DATE-M1',
            'admission_date' => '01/04/2026',
            'date_of_birth' => new DateTimeImmutable('2015-05-06'),
            'roll_number' => 3,
        ])),
        mapImportRow($headers, validImportRowMap([
            'admission_no' => 'DATE-M2',
            'admission_date' => '01/04/2026',
            'date_of_birth' => '25/06/2015',
            'roll_number' => 4,
        ])),
    ], 'xlsx');

    $parsedMixed = (new ParseStudentImportFile)->handle($admin, $mixedDobPath, 'xlsx');
    $validatedMixed = (new ValidateStudentImport)->handle($admin, $structure['year'], $parsedMixed);

    expect($validatedMixed->isValid())->toBeFalse()
        ->and(collect($validatedMixed->errors)->where('column', 'date_of_birth')->pluck('message')->implode(' '))
        ->toContain('US dates');

    @unlink($mixedDobPath);
});

test('parser rejects non-UTF-8 CSV bytes and spreadsheets exceeding 5000 physical rows', function () {
    $school = School::factory()->active()->create();
    $admin = createImportActor($school, SchoolRole::Admin);
    createImportSchoolStructure($school);

    // Windows-1252 / ISO-8859-1 encoded CSV must be rejected cleanly with save-as-UTF-8 guidance.
    $ansiCsvPath = tempnam(sys_get_temp_dir(), 'imp_test_').'.csv';
    file_put_contents(
        $ansiCsvPath,
        "admission_no*,admission_date*,name*,date_of_birth*,gender*,grade*,section*,father_name,father_mobile,primary_contact*\n"
        ."ANSI-1,01/04/2026,Jos\xE9 Patel,05/06/2015,Male,Class 5,A,Father,9876543210,father\n",
    );

    expect(fn () => (new ParseStudentImportFile)->handle($admin, $ansiCsvPath, 'csv'))
        ->toThrow(ValidationException::class, 'CSV UTF-8');
    @unlink($ansiCsvPath);

    // > 5,000 physical blank rows aborted before exhausting resources.
    $blankCsvPath = tempnam(sys_get_temp_dir(), 'imp_test_').'.csv';
    $handle = fopen($blankCsvPath, 'wb');
    fwrite($handle, "admission_no*,admission_date*,name*,date_of_birth*,gender*,grade*,section*,primary_contact*\n");
    for ($i = 0; $i < 5002; $i++) {
        fwrite($handle, "   ,   ,   \n");
    }
    fclose($handle);

    expect(fn () => (new ParseStudentImportFile)->handle($admin, $blankCsvPath, 'csv'))
        ->toThrow(ValidationException::class, '5000');
    @unlink($blankCsvPath);
});
