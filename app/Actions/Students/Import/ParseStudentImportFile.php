<?php

namespace App\Actions\Students\Import;

use App\Actions\Students\Import\Data\ParsedStudentImport;
use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class ParseStudentImportFile
{
    /**
     * Parse an uploaded XLSX or CSV spreadsheet using explicit reader type without loading unsafe metadata or > 1,000 rows.
     */
    public function handle(User $actor, string $filePath, string $extension): ParsedStudentImport
    {
        CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('import', Student::class);

        $normalizedExtension = Str::lower(trim($extension));

        if (! in_array($normalizedExtension, StudentImportColumns::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => __('Only XLSX and CSV files are supported for student import.'),
            ]);
        }

        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded import file could not be accessed. Please upload it again.'),
            ]);
        }

        $fileSize = filesize($filePath);

        if ($fileSize === false || $fileSize === 0 || $fileSize > StudentImportColumns::MAX_FILE_BYTES) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded file must be non-empty and no larger than 5 MB.'),
            ]);
        }

        $canViewSensitive = $actor->can('viewSensitive', Student::class);
        $reader = null;

        try {
            $reader = SimpleExcelReader::create($filePath, $normalizedExtension)
                ->noHeaderRow()
                ->keepFormulas();

            $columnIndexMap = [];
            $presentColumns = [];
            $rows = [];
            $headerParsed = false;
            $spreadsheetRowNumber = 0;

            foreach ($reader->getRows() as $rawRow) {
                $spreadsheetRowNumber++;

                if (! is_array($rawRow)) {
                    continue;
                }

                if (! $headerParsed) {
                    if ($this->isBlankSpreadsheetRow($rawRow)) {
                        throw ValidationException::withMessages([
                            'file' => __('The first row of the uploaded spreadsheet must contain column headers.'),
                        ]);
                    }

                    [$columnIndexMap, $presentColumns] = $this->mapHeaderRow($rawRow, $canViewSensitive);
                    $headerParsed = true;

                    if ($columnIndexMap === []) {
                        throw ValidationException::withMessages([
                            'file' => __('No recognized student import columns were found in the header row. Download the template and try again.'),
                        ]);
                    }

                    continue;
                }

                if ($this->isBlankSpreadsheetRow($rawRow)) {
                    continue;
                }

                if (count($rows) >= StudentImportColumns::MAX_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => __('The uploaded file exceeds the limit of :max rows per import. Split it into smaller batches.', [
                            'max' => StudentImportColumns::MAX_ROWS,
                        ]),
                    ]);
                }

                $cells = [];

                foreach ($columnIndexMap as $colIndex => $columnKey) {
                    $cells[$columnKey] = $rawRow[$colIndex] ?? null;
                }

                $rows[] = [
                    'row_number' => $spreadsheetRowNumber,
                    'cells' => $cells,
                ];
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded spreadsheet is corrupt or invalid for the selected file type.'),
            ]);
        } finally {
            $reader?->close();
        }

        if (! $headerParsed) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded spreadsheet is empty.'),
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded spreadsheet contains headers only and has no student rows to import.'),
            ]);
        }

        $missingRequired = array_values(array_diff(
            StudentImportColumns::REQUIRED_HEADERS,
            $presentColumns,
        ));

        return new ParsedStudentImport(
            presentColumns: $presentColumns,
            missingRequiredColumns: $missingRequired,
            rows: $rows,
        );
    }

    /**
     * Map spreadsheet column indices to canonical student column keys while ignoring unknown, system, and unauthorized columns.
     *
     * @param  array<array-key, mixed>  $rawHeaderRow
     * @return array{0: array<int, string>, 1: list<string>}
     */
    private function mapHeaderRow(array $rawHeaderRow, bool $canViewSensitive): array
    {
        $columnIndexMap = [];
        $presentColumns = [];
        $seen = [];

        foreach ($rawHeaderRow as $index => $rawHeader) {
            if (! is_int($index)) {
                continue;
            }

            $canonical = StudentImportColumns::normalizeHeader($rawHeader);

            if ($canonical === null || isset($seen[$canonical])) {
                continue;
            }

            if (! $canViewSensitive && in_array($canonical, Student::VIEW_SENSITIVE_FIELDS, true)) {
                continue;
            }

            $seen[$canonical] = true;
            $columnIndexMap[$index] = $canonical;
            $presentColumns[] = $canonical;
        }

        return [$columnIndexMap, $presentColumns];
    }

    /**
     * Determine whether every cell in a spreadsheet row is null or whitespace.
     *
     * @param  array<array-key, mixed>  $rawRow
     */
    private function isBlankSpreadsheetRow(array $rawRow): bool
    {
        foreach ($rawRow as $value) {
            if ($value instanceof DateTimeInterface) {
                return false;
            }

            if (is_bool($value)) {
                return false;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
