<?php

use App\Actions\Students\Import\ImportStudents;
use App\Actions\Students\Import\StudentImportColumns;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Livewire\Students\Import as StudentsImport;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Membership;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
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

    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'imp_screen_test_*') ?: [] as $tmpFile) {
        @unlink($tmpFile);
    }
});

/**
 * Provision an active member in the school with the specified role.
 */
function createImportScreenMember(School $school, SchoolRole $role): User
{
    $user = User::factory()->create();

    Membership::factory()->active()->for($user)->for($school)->create([
        'status' => MembershipStatus::Active,
    ]);

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles', 'permissions');
    $user->assignRole($role->value);
    $user->unsetRelation('roles', 'permissions');
    setPermissionsTeamId(null);

    return $user;
}

/**
 * Seed academic year, grade, and sections for student import screen tests.
 *
 * @return array{year: AcademicYear, grade: Grade, sectionA: Section, sectionB: Section}
 */
function createImportScreenStructure(School $school): array
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

    CurrentSchool::forget();

    return compact('year', 'grade', 'sectionA', 'sectionB');
}

/**
 * Build a live Fake UploadedFile for XLSX or CSV from header and row data.
 *
 * @param  list<string>  $headers
 * @param  list<list<mixed>>  $rows
 */
function makeScreenUploadFile(string $filename, array $headers, array $rows, string $extension = 'xlsx'): UploadedFile
{
    $base = tempnam(sys_get_temp_dir(), 'imp_screen_test_');
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

    $binary = (string) file_get_contents($path);
    @unlink($path);

    return UploadedFile::fake()->createWithContent($filename, $binary);
}

/**
 * Decode a Livewire download effect to a temporary XLSX file and return its path for reading.
 */
function writeDownloadedXlsxFromEffect(mixed $lw): string
{
    $encoded = (string) data_get($lw->effects, 'download.content', '');
    $binary = base64_decode($encoded, true);
    expect($binary)->not->toBeFalse();

    $base = tempnam(sys_get_temp_dir(), 'imp_screen_test_');
    $path = $base.'.xlsx';

    if (is_string($base) && file_exists($base)) {
        @unlink($base);
    }

    file_put_contents($path, (string) $binary);

    return $path;
}

test('import route and Students Index button enforce school role permissions and isolation', function (
    SchoolRole $role,
    int $expectedImportStatus,
    bool $seesButtonOnIndex,
) {
    $school = School::factory()->active()->create();
    $user = createImportScreenMember($school, $role);
    createImportScreenStructure($school);

    $this->actingAs($user)
        ->get(route('students.import', ['school' => $school->id]))
        ->assertStatus($expectedImportStatus);

    if ($role !== SchoolRole::Parent && $role !== SchoolRole::Student) {
        $indexResponse = $this->actingAs($user)
            ->get(route('students.index', ['school' => $school->id]))
            ->assertOk();

        if ($seesButtonOnIndex) {
            $indexResponse->assertSee('data-test="import-students-button"', false);
        } else {
            $indexResponse->assertDontSee('data-test="import-students-button"', false);
        }
    }

    if ($expectedImportStatus !== 200) {
        CurrentSchool::set($school);
        setPermissionsTeamId($school->id);
        Livewire::actingAs($user)->test(StudentsImport::class)->assertForbidden();
    }
})->with([
    'owner' => [SchoolRole::Owner, 200, true],
    'admin' => [SchoolRole::Admin, 200, true],
    'teacher' => [SchoolRole::Teacher, 403, false],
    'accountant' => [SchoolRole::Accountant, 403, false],
    'parent' => [SchoolRole::Parent, 403, false],
    'student' => [SchoolRole::Student, 403, false],
]);

test('guests redirect to login and non-members receive 404 on student import route', function () {
    $school = School::factory()->active()->create();
    $outsider = User::factory()->create();
    $url = route('students.import', ['school' => $school->id]);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($outsider)->get($url)->assertNotFound();
});

test('Livewire snapshot contains zero parsed row values, student names, or sensitive data after dry run', function () {
    $school = School::factory()->active()->create();
    $admin = createImportScreenMember($school, SchoolRole::Admin);
    $structure = createImportScreenStructure($school);

    $headers = StudentImportColumns::templateHeaders(true);
    $rows = [
        [
            'ADM-SNAP-1', '01/04/2026', 'CanaryStudentName', '05/06/2015', 'Male',
            'Class 5', 'A', '1', 'CanaryFatherName', '9876543210', '', '', '', '', '',
            'father', '12 MG Road', 'Ahmedabad', 'Gujarat', '380001', 'O+', 'Hindi',
            'Old School', 'OBC', 'CanaryReligion', '918273645123', '55667788991', '7391',
        ],
        [
            'ADM-SNAP-2', '01/04/2026', '=cmd|CanaryFormula', '31/02/2015', 'Female',
            'Class 5', 'A', '2', 'AnotherFather', '9876543219', '', '', '', '', '',
            'father', '', '', '', '', '', '', '', 'General', 'Hindu', '', '', 'CanaryBad99',
        ],
    ];

    $upload = makeScreenUploadFile('snapshot-check.xlsx', $headers, $rows, 'xlsx');

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $lw = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $upload)
        ->call('dryRun')
        ->assertSet('hasDryRun', true)
        ->assertSet('totalRows', 2)
        ->assertSet('validCount', 1)
        ->assertSet('invalidCount', 1);

    $snapshotJson = json_encode($lw->snapshot) ?: '';

    foreach ([
        'CanaryStudentName',
        'CanaryFatherName',
        'CanaryReligion',
        '918273645123',
        '55667788991',
        '7391',
        '9876543210',
        'ADM-SNAP-1',
        'CanaryFormula',
        'CanaryBad99',
        '31/02/2015',
    ] as $canary) {
        expect($snapshotJson)->not->toContain($canary);
    }
});

test('screen supports downloading template, inspecting dry-run errors with formula-escaped error report, and completing import while cleaning up temp file', function () {
    $school = School::factory()->active()->create(['name' => 'Sunrise Academy']);
    $admin = createImportScreenMember($school, SchoolRole::Admin);
    $structure = createImportScreenStructure($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    // 1. Download template via screen component and verify sheets & instructions
    $slug = Str::slug($school->name);
    $templateComponent = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->call('downloadTemplate')
        ->assertFileDownloaded("{$slug}-student-import-template.xlsx");

    $downloadedTemplatePath = writeDownloadedXlsxFromEffect($templateComponent);
    $templateReader = SimpleExcelReader::create($downloadedTemplatePath, 'xlsx');
    expect($templateReader->getSheetNames())->toBe(['Students', 'Instructions']);
    $templateReader->close();
    $templateDataRows = SimpleExcelReader::create($downloadedTemplatePath, 'xlsx')
        ->fromSheetName('Students')
        ->noHeaderRow()
        ->getRows()
        ->all();
    expect($templateDataRows)->toHaveCount(1); // headers only, no fake child row
    @unlink($downloadedTemplatePath);

    // 2. Upload CSV spreadsheet with formula payload + validation error -> dryRun -> downloadErrorReport
    $headers = StudentImportColumns::templateHeaders(true);
    $badUpload = makeScreenUploadFile('bad-students.csv', $headers, [
        [
            '=1+1', '01/04/2026', 'CanaryBadName', '31/02/2015', 'Male',
            'Class 5', 'A', '1', 'Father One', '9876543210', '', '', '', '', '',
            'father', '', '', '', '', '', '', '', '', '', '', '', 'CanaryAadhaar',
        ],
    ], 'csv');

    $errorComponent = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $badUpload)
        ->call('dryRun')
        ->assertSet('totalRows', 1)
        ->assertSet('validCount', 0)
        ->assertSet('invalidCount', 1)
        ->assertSee('data-test="download-error-report-button"', false)
        ->call('downloadErrorReport')
        ->assertFileDownloaded("{$slug}-student-import-errors.xlsx");

    $reportPath = writeDownloadedXlsxFromEffect($errorComponent);
    $reportRows = SimpleExcelReader::create($reportPath, 'xlsx')
        ->fromSheetName('Failed rows')
        ->getRows()
        ->all();

    expect($reportRows)->toHaveCount(1)
        ->and($reportRows[0]['admission_no*'])->toBe("'=1+1")
        ->and($reportRows[0]['errors'])->not->toContain('31/02/2015', 'CanaryAadhaar');
    @unlink($reportPath);

    // 3. Upload fixed CSV file -> dryRun passes -> requires consent -> confirmImport -> redirects & deletes temp file
    $goodUpload = makeScreenUploadFile('valid-students.csv', StudentImportColumns::templateHeaders(false), [
        [
            'SCR-001', '01/04/2026', 'Diya Verma', '05/06/2015', 'Female',
            'Class 5', 'A', '1', 'Rajesh Verma', '9898989898', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
        [
            'SCR-002', '01/04/2026', 'Kabir Verma', '08/09/2016', 'Male',
            '5', 'A', '2', 'Rajesh Verma', '+91 98989 89898', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
    ], 'csv');

    $lw = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $goodUpload)
        ->call('dryRun')
        ->assertSet('totalRows', 2)
        ->assertSet('validCount', 2)
        ->assertSet('invalidCount', 0)
        ->assertSee('data-test="dry-run-passed-callout"', false);

    $uploadedFile = $lw->get('file');
    expect($uploadedFile)->toBeInstanceOf(TemporaryUploadedFile::class);
    $tempRealPath = $uploadedFile->getRealPath();
    expect(file_exists($tempRealPath))->toBeTrue();

    // Selecting a new file resets consent_confirmed automatically.
    $lw->set('consent_confirmed', true)
        ->set('file', $badUpload)
        ->assertSet('consent_confirmed', false)
        ->set('file', $goodUpload)
        ->call('dryRun');

    $refreshedFile = $lw->get('file');
    expect($refreshedFile)->toBeInstanceOf(TemporaryUploadedFile::class);
    $tempRealPath = $refreshedFile->getRealPath();
    expect(file_exists($tempRealPath))->toBeTrue();

    // Refuses confirm when DPDP consent box is unchecked
    $lw->call('confirmImport')
        ->assertHasErrors(['consent_confirmed']);
    expect(Student::query()->count())->toBe(0);

    // Confirm with consent -> imports students, deletes temp file, redirects to index
    $lw->set('consent_confirmed', true)
        ->call('confirmImport')
        ->assertHasNoErrors()
        ->assertRedirect(route('students.index', ['school' => $school->id]));

    expect(Student::query()->count())->toBe(2)
        ->and(Guardian::query()->count())->toBe(1) // Siblings share Rajesh Verma
        ->and(Enrollment::query()->count())->toBe(2)
        ->and(file_exists($tempRealPath))->toBeFalse();
});

test('confirmImport detects database changes between dry run and confirm and imports zero rows', function () {
    $school = School::factory()->active()->create();
    $admin = createImportScreenMember($school, SchoolRole::Admin);
    $structure = createImportScreenStructure($school);

    $headers = StudentImportColumns::templateHeaders(false);
    $upload = makeScreenUploadFile('stale-dry-run.csv', $headers, [
        [
            'STALE-001', '01/04/2026', 'Aarav Patel', '05/06/2015', 'Male',
            'Class 5', 'A', '1', 'Ramesh Patel', '9876543210', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
        [
            'STALE-002', '01/04/2026', 'Meera Patel', '06/07/2016', 'Female',
            'Class 5', 'B', '1', 'Ramesh Patel', '9876543210', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
    ], 'csv');

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $lw = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $upload)
        ->call('dryRun')
        ->assertSet('validCount', 2)
        ->assertSet('invalidCount', 0);

    // Concurrent admission claims STALE-002 after dry run passed.
    Student::factory()->for($school)->create(['admission_no' => 'STALE-002']);

    $lw->set('consent_confirmed', true)
        ->call('confirmImport')
        ->assertHasErrors(['file'])
        ->assertSet('invalidCount', 1)
        ->assertSet('validCount', 1);

    expect(Student::query()->where('admission_no', 'STALE-001')->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(1);
});

test('screen validates unsupported file extensions and enforces hourly confirm and dry-run rate limits', function () {
    $school = School::factory()->active()->create();
    $admin = createImportScreenMember($school, SchoolRole::Admin);
    $structure = createImportScreenStructure($school);

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    // Invalid extension (.txt) rejected by Livewire validation rule before parsing
    $badExtUpload = UploadedFile::fake()->createWithContent('notes.txt', 'hello,world');

    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $badExtUpload)
        ->call('dryRun')
        ->assertHasErrors(['file']);

    $validUpload = makeScreenUploadFile('valid.csv', StudentImportColumns::templateHeaders(false), [
        [
            'RL-SCR-1', '01/04/2026', 'Karan Mehta', '05/06/2015', 'Male',
            'Class 5', 'A', '1', 'Suresh Mehta', '9876500001', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
    ], 'csv');

    // Dry-run / parse limiter triggers after 30 hits across dryRun, downloadErrorReport, and confirmImport
    $dryRunKey = StudentsImport::dryRunRateLimitKey($school->id);
    RateLimiter::clear($dryRunKey);
    for ($i = 0; $i < StudentsImport::DRY_RUN_HOURLY_LIMIT; $i++) {
        RateLimiter::hit($dryRunKey, 3600);
    }

    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $validUpload)
        ->call('dryRun')
        ->assertHasErrors(['file']);

    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $validUpload)
        ->call('downloadErrorReport')
        ->assertHasErrors(['file']);

    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $validUpload)
        ->set('consent_confirmed', true)
        ->call('confirmImport')
        ->assertHasErrors(['file']);

    // Verify calling downloadErrorReport on a fresh quota consumes a hit cleanly.
    RateLimiter::clear($dryRunKey);
    $badCsv = makeScreenUploadFile('bad-rl.csv', StudentImportColumns::templateHeaders(false), [
        [
            'RL-BAD', '01/04/2026', 'Name', '31/02/2015', 'Male',
            'Class 5', 'A', '1', 'Father', '9876500001', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ],
    ], 'csv');
    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $badCsv)
        ->call('downloadErrorReport')
        ->assertFileDownloaded();
    expect(RateLimiter::attempts($dryRunKey))->toBe(1);

    RateLimiter::clear($dryRunKey);

    // Confirm limiter triggers on 11th import in an hour
    $confirmKey = ImportStudents::rateLimitKey($school->id);
    RateLimiter::clear($confirmKey);
    for ($i = 0; $i < ImportStudents::HOURLY_LIMIT; $i++) {
        RateLimiter::hit($confirmKey, 3600);
    }

    Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $validUpload)
        ->set('consent_confirmed', true)
        ->call('confirmImport')
        ->assertHasErrors(['file']);

    expect(Student::query()->count())->toBe(0);
});

test('screen caps errorsList in snapshot at 100 entries while error report includes all rows', function () {
    $school = School::factory()->active()->create();
    $admin = createImportScreenMember($school, SchoolRole::Admin);
    $structure = createImportScreenStructure($school);

    $headers = StudentImportColumns::templateHeaders(false);
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = [
            "BIG-{$i}", '01/04/2026', "Student {$i}", '31/02/2015', 'Male',
            'Class 5', 'A', (string) $i, 'Father', '9876543210', '', '', '', '', '',
            'father', '', '', '', '', '', '', '',
        ];
    }

    $upload = makeScreenUploadFile('many-errors.csv', $headers, $rows, 'csv');

    CurrentSchool::set($school);
    setPermissionsTeamId($school->id);

    $lw = Livewire::actingAs($admin)
        ->test(StudentsImport::class)
        ->set('academic_year_id', $structure['year']->id)
        ->set('file', $upload)
        ->call('dryRun')
        ->assertSet('totalRows', 120)
        ->assertSet('invalidCount', 120)
        ->assertSet('totalErrorCount', 120)
        ->assertSet('remainingErrorCount', 20)
        ->assertSee('data-test="remaining-errors-notice"', false);

    expect($lw->get('errorsList'))->toHaveCount(100);

    $lw->call('downloadErrorReport')->assertFileDownloaded();
    $reportPath = writeDownloadedXlsxFromEffect($lw);
    $reportDetailRows = SimpleExcelReader::create($reportPath, 'xlsx')
        ->fromSheetName('Error details')
        ->getRows()
        ->all();

    expect($reportDetailRows)->toHaveCount(120);
    @unlink($reportPath);
});
