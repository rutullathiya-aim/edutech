<?php

namespace App\Actions\Students\Import\Data;

readonly class StudentImportValidationResult
{
    /**
     * @param  list<array{row: int, column: string, message: string}>  $errors
     * @param  list<array{
     *     row_number: int,
     *     student: array<string, mixed>,
     *     section_id: string,
     *     roll_number: int|null,
     *     enrolled_on: string,
     *     guardians: list<array{
     *         existing_guardian_id: string|null,
     *         name: string,
     *         normalized_name_key: string,
     *         mobile: string|null,
     *         relationship: string,
     *         is_primary: bool,
     *     }>
     * }>  $preparedRows
     */
    public function __construct(
        public string $academicYearId,
        public string $academicYearName,
        public int $totalRows,
        public int $validRowCount,
        public int $invalidRowCount,
        public array $errors,
        public array $preparedRows,
    ) {}

    /**
     * Determine whether any row failed validation.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Determine whether at least one row exists and every row is valid.
     */
    public function isValid(): bool
    {
        return $this->totalRows > 0 && $this->errors === [];
    }

    /**
     * Return errors mapped by 1-based spreadsheet row number for error report generation.
     *
     * @return array<int, list<array{column: string, message: string}>>
     */
    public function errorsByRow(): array
    {
        $grouped = [];

        foreach ($this->errors as $error) {
            $grouped[$error['row']][] = [
                'column' => $error['column'],
                'message' => $error['message'],
            ];
        }

        return $grouped;
    }
}
