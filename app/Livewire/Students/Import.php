<?php

namespace App\Livewire\Students;

use App\Actions\Students\Import\BuildImportErrorReport;
use App\Actions\Students\Import\BuildImportTemplate;
use App\Actions\Students\Import\Data\ParsedStudentImport;
use App\Actions\Students\Import\Data\StudentImportValidationResult;
use App\Actions\Students\Import\ImportStudents;
use App\Actions\Students\Import\ParseStudentImportFile;
use App\Actions\Students\Import\ValidateStudentImport;
use App\Livewire\Students\Concerns\InteractsWithStudentScreens;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property-read School $school
 * @property-read bool $canViewSensitive
 * @property-read Collection<int, AcademicYear> $openAcademicYears
 */
#[Title('Import students')]
class Import extends Component
{
    use InteractsWithStudentScreens, WithFileUploads;

    /**
     * Maximum dry-run validations allowed per school within one hour.
     */
    public const int DRY_RUN_HOURLY_LIMIT = 30;

    public ?TemporaryUploadedFile $file = null;

    public string $academic_year_id = '';

    public bool $consent_confirmed = false;

    #[Locked]
    public bool $hasDryRun = false;

    #[Locked]
    public int $totalRows = 0;

    #[Locked]
    public int $validCount = 0;

    #[Locked]
    public int $invalidCount = 0;

    /**
     * Sanitized dry-run validation issues containing only row number, column key, and rule message.
     *
     * @var list<array{row: int, column: string, message: string}>
     */
    #[Locked]
    public array $errorsList = [];

    /**
     * Authorize student import access and default to the current open academic year.
     */
    public function mount(): void
    {
        $this->authorize('import', Student::class);

        $openYears = $this->openAcademicYears;
        $defaultYear = $openYears->firstWhere('is_current', true) ?? $openYears->first();

        $this->academic_year_id = $defaultYear !== null ? $defaultYear->id : '';
    }

    /**
     * Reset previous dry-run summary whenever the uploaded file changes.
     */
    public function updatedFile(): void
    {
        $this->resetDryRunState();
        $this->resetValidation('file');
    }

    /**
     * Reset previous dry-run summary whenever the target academic year changes.
     */
    public function updatedAcademicYearId(): void
    {
        $this->resetDryRunState();
        $this->resetValidation('academic_year_id');
    }

    /**
     * Get open (non-closed) academic years for the current school.
     *
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function openAcademicYears(): Collection
    {
        return AcademicYear::query()
            ->whereNull('closed_at')
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Compute the rate-limit key for dry-run validation attempts per school.
     */
    public static function dryRunRateLimitKey(string $schoolId): string
    {
        return "student-import-dry-run:{$schoolId}";
    }

    /**
     * Stream the two-sheet XLSX import template customized for the school's grades, sections, and permissions.
     */
    public function downloadTemplate(BuildImportTemplate $templateBuilder): StreamedResponse
    {
        $this->authorize('import', Student::class);

        return $templateBuilder->toDownloadResponse(
            $this->actor(),
            $this->academic_year_id !== '' ? $this->academic_year_id : null,
        );
    }

    /**
     * Parse and validate the uploaded spreadsheet against current school records without saving any rows.
     */
    public function dryRun(ParseStudentImportFile $parser, ValidateStudentImport $validator): void
    {
        $this->authorize('import', Student::class);

        $actor = $this->actor();
        $school = $this->school;

        $this->resetDryRunState();

        $rateKey = self::dryRunRateLimitKey($school->id);

        if (RateLimiter::tooManyAttempts($rateKey, self::DRY_RUN_HOURLY_LIMIT)) {
            $retryMinutes = max(1, (int) ceil(RateLimiter::availableIn($rateKey) / 60));

            throw ValidationException::withMessages([
                'file' => __('Too many validation runs for this school. Try again in :minutes minute(s).', [
                    'minutes' => $retryMinutes,
                ]),
            ]);
        }

        [$academicYear, $filePath, $extension] = $this->validateUploadAndYear();

        RateLimiter::hit($rateKey, 3600);

        $parsed = $parser->handle($actor, $filePath, $extension);
        $result = $validator->handle($actor, $academicYear, $parsed);

        $this->applyValidationResult($result);
    }

    /**
     * Stream a formula-safe XLSX error report for rows that failed validation.
     */
    public function downloadErrorReport(
        ParseStudentImportFile $parser,
        ValidateStudentImport $validator,
        BuildImportErrorReport $errorReportBuilder,
    ): ?StreamedResponse {
        $this->authorize('import', Student::class);

        $actor = $this->actor();
        [$academicYear, $filePath, $extension] = $this->validateUploadAndYear();

        $parsed = $parser->handle($actor, $filePath, $extension);
        $result = $validator->handle($actor, $academicYear, $parsed);

        $this->applyValidationResult($result);

        if (! $result->hasErrors()) {
            return null;
        }

        return $errorReportBuilder->toDownloadResponse($actor, $parsed, $result);
    }

    /**
     * Re-verify the uploaded file against live records, atomically import all rows, delete the temp file, and redirect.
     */
    public function confirmImport(
        ParseStudentImportFile $parser,
        ValidateStudentImport $validator,
        ImportStudents $importer,
    ): void {
        $this->authorize('import', Student::class);

        $actor = $this->actor();

        [$academicYear, $filePath, $extension] = $this->validateUploadAndYear([
            'consent_confirmed' => ['required', 'accepted'],
        ]);

        /** @var ParsedStudentImport $parsed */
        $parsed = $parser->handle($actor, $filePath, $extension);
        $validation = $validator->handle($actor, $academicYear, $parsed);

        if (! $validation->isValid()) {
            $this->applyValidationResult($validation);
            $this->addError(
                'file',
                __('Validation errors were found against current school records. Review the errors below before importing.'),
            );

            return;
        }

        $importResult = $importer->handle(
            actor: $actor,
            academicYearId: $academicYear->id,
            filePath: $filePath,
            extension: $extension,
            consentConfirmed: $this->consent_confirmed,
        );

        $uploadedFile = $this->file;
        $this->file = null;
        $this->consent_confirmed = false;
        $this->resetDryRunState();

        if ($uploadedFile instanceof TemporaryUploadedFile) {
            $uploadedFile->delete();
        }

        Flux::toast(
            variant: 'success',
            text: __('Imported :count student(s) into :year.', [
                'count' => $importResult->importedCount,
                'year' => $importResult->academicYearName,
            ]),
        );

        $this->redirect(
            route('students.index', ['school' => $this->school->id]),
            navigate: true,
        );
    }

    /**
     * Render the bulk student import screen.
     */
    public function render(): View
    {
        return view('livewire.students.import');
    }

    /**
     * Validate target academic year (scoped, open) and uploaded temporary spreadsheet, returning [AcademicYear, realPath, extension].
     *
     * @param  array<string, list<string>>  $extraRules
     * @return array{0: AcademicYear, 1: string, 2: string}
     */
    private function validateUploadAndYear(array $extraRules = []): array
    {
        $school = $this->school;

        $rules = array_merge([
            'academic_year_id' => [
                'bail',
                'required',
                'string',
                Rule::exists(AcademicYear::class, 'id')->where(
                    static fn ($query) => $query->where('school_id', $school->id),
                ),
            ],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
        ], $extraRules);

        $this->validate($rules);

        $academicYear = AcademicYear::query()
            ->whereKey($this->academic_year_id)
            ->firstOrFail();

        if ($academicYear->isClosed()) {
            throw ValidationException::withMessages([
                'academic_year_id' => __('Cannot import students into a closed academic year.'),
            ]);
        }

        $uploadedFile = $this->file;

        if (! $uploadedFile instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages([
                'file' => __('Upload an XLSX or CSV file first.'),
            ]);
        }

        $filePath = $uploadedFile->getRealPath();

        if ($filePath === '' || ! is_file($filePath)) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded temporary file expired. Please upload it again.'),
            ]);
        }

        $extension = Str::lower(trim($uploadedFile->getClientOriginalExtension()));

        return [$academicYear, $filePath, $extension];
    }

    /**
     * Copy only sanitized validation counts and error locations into locked component properties.
     */
    private function applyValidationResult(StudentImportValidationResult $result): void
    {
        $this->hasDryRun = true;
        $this->totalRows = $result->totalRows;
        $this->validCount = $result->validRowCount;
        $this->invalidCount = $result->invalidRowCount;
        $this->errorsList = $result->errors;
    }

    /**
     * Clear any prior validation summary state.
     */
    private function resetDryRunState(): void
    {
        $this->hasDryRun = false;
        $this->totalRows = 0;
        $this->validCount = 0;
        $this->invalidCount = 0;
        $this->errorsList = [];
    }

    /**
     * Retrieve the authenticated user instance or abort.
     */
    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }
}
