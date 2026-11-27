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
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
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
            [$reader, $rawRows] = $this->openRowSource($filePath, $normalizedExtension);

            $columnIndexMap = [];
            $presentColumns = [];
            $rows = [];
            $headerParsed = false;
            $spreadsheetRowNumber = 0;

            foreach ($rawRows as $rawRow) {
                $spreadsheetRowNumber++;

                if ($spreadsheetRowNumber > StudentImportColumns::MAX_PHYSICAL_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => __('The uploaded spreadsheet exceeds the limit of :max rows including blank rows.', [
                            'max' => StudentImportColumns::MAX_PHYSICAL_ROWS,
                        ]),
                    ]);
                }

                $this->assertValidUtf8Row($rawRow);

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
     * Open the appropriate CSV or XLSX row iterator using private temp storage for XLSX extraction.
     *
     * @return array{0: SimpleExcelReader|Reader, 1: iterable<array<array-key, mixed>>}
     */
    private function openRowSource(string $filePath, string $extension): array
    {
        if ($extension === 'csv') {
            // CSV cells have no cached formula result; keep literal '=' text instead of letting OpenSpout blank it out.
            $csvReader = SimpleExcelReader::create($filePath, 'csv')
                ->noHeaderRow()
                ->keepFormulas();

            return [$csvReader, $csvReader->getRows()];
        }

        $xlsxReader = StudentImportColumns::openXlsxReader($filePath);
        $firstSheet = null;

        foreach ($xlsxReader->getSheetIterator() as $index => $sheet) {
            if ($index === 1) {
                $firstSheet = $sheet;
                break;
            }
        }

        if ($firstSheet === null) {
            throw ValidationException::withMessages([
                'file' => __('The uploaded spreadsheet is corrupt or invalid for the selected file type.'),
            ]);
        }

        $rows = (static function () use ($firstSheet): iterable {
            /** @var Row $row */
            foreach ($firstSheet->getRowIterator() as $row) {
                $values = array_map(
                    static fn (Cell $cell): mixed => $cell instanceof FormulaCell
                        ? $cell->getComputedValue()
                        : $cell->getValue(),
                    $row->cells,
                );
                ksort($values);

                yield $values;
            }
        })();

        return [$xlsxReader, $rows];
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

    /**
     * Reject non-UTF-8 byte sequences (for example ANSI CSVs saved by Excel) so database writes never fail silently.
     *
     * @param  array<array-key, mixed>  $rawRow
     */
    private function assertValidUtf8Row(array $rawRow): void
    {
        foreach ($rawRow as $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([
                    'file' => __('Save the file as CSV UTF-8 or XLSX.'),
                ]);
            }
        }
    }
}
