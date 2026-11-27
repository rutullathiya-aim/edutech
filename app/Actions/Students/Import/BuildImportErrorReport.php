<?php

namespace App\Actions\Students\Import;

use App\Actions\Students\Import\Data\ParsedStudentImport;
use App\Actions\Students\Import\Data\StudentImportValidationResult;
use App\Models\Student;
use App\Models\User;
use App\Support\SpreadsheetCell;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildImportErrorReport
{
    /**
     * Generate a temporary XLSX error report containing only failed rows with formula-escaped cells and an errors column.
     */
    public function buildTempFile(
        User $actor,
        ParsedStudentImport $parsed,
        StudentImportValidationResult $validation,
    ): string {
        CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('import', Student::class);

        $canViewSensitive = $actor->can('viewSensitive', Student::class);
        $allowedColumns = $this->resolveExportColumns($parsed->presentColumns, $canViewSensitive);
        $errorsByRow = $validation->errorsByRow();

        $tempPath = StudentImportColumns::allocateTempXlsxPath('student-errors');
        $writer = SimpleExcelWriter::create($tempPath, 'xlsx')
            ->noHeaderRow()
            ->nameCurrentSheet('Failed rows');

        $writer->addRow([
            ...array_map(
                static fn (string $column): string => StudentImportColumns::DEFINITIONS[$column] ? "{$column}*" : $column,
                $allowedColumns,
            ),
            'errors',
        ]);

        foreach ($parsed->rows as $rowData) {
            $rowNumber = $rowData['row_number'];
            $rowErrors = $errorsByRow[$rowNumber] ?? null;

            if ($rowErrors === null) {
                continue;
            }

            $cells = $rowData['cells'];
            $exportRow = [];

            foreach ($allowedColumns as $column) {
                $value = $cells[$column] ?? null;
                $rawString = is_string($value)
                    ? (trim($value) === '' ? '' : $value)
                    : (StudentImportColumns::normalizeCellString($value) ?? '');

                $exportRow[] = SpreadsheetCell::escapeFormula($rawString);
            }

            $errorSummary = implode('; ', array_map(
                static fn (array $error): string => "Row {$rowNumber} [{$error['column']}]: {$error['message']}",
                $rowErrors,
            ));
            $exportRow[] = SpreadsheetCell::escapeFormula($errorSummary);

            $writer->addRow($exportRow);
        }

        $writer->addNewSheetAndMakeItCurrent('Error details');
        $writer->addRow(['row', 'column', 'message']);

        foreach ($validation->errors as $error) {
            $writer->addRow([
                $error['row'],
                SpreadsheetCell::escapeFormula($error['column']),
                SpreadsheetCell::escapeFormula($error['message']),
            ]);
        }

        $writer->close();

        return $tempPath;
    }

    /**
     * Stream the XLSX error report as a browser download and clean up the temporary file afterwards.
     */
    public function toDownloadResponse(
        User $actor,
        ParsedStudentImport $parsed,
        StudentImportValidationResult $validation,
    ): StreamedResponse {
        $school = CurrentSchool::getOrFail();
        $tempPath = $this->buildTempFile($actor, $parsed, $validation);
        $slug = Str::slug($school->name) ?: 'school';
        $filename = "{$slug}-student-import-errors.xlsx";

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
     * Order columns consistently for export while stripping sensitive fields unless authorized.
     *
     * @param  list<string>  $presentColumns
     * @return list<string>
     */
    private function resolveExportColumns(array $presentColumns, bool $canViewSensitive): array
    {
        $allowed = StudentImportColumns::allowedColumns($canViewSensitive);
        $ordered = array_values(array_filter(
            $allowed,
            static fn (string $column): bool => in_array($column, $presentColumns, true),
        ));

        return $ordered !== [] ? $ordered : $allowed;
    }
}
