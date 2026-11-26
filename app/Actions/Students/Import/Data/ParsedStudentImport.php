<?php

namespace App\Actions\Students\Import\Data;

readonly class ParsedStudentImport
{
    /**
     * @param  list<string>  $presentColumns  Canonical recognized column keys found in the header row
     * @param  list<string>  $missingRequiredColumns  Required column keys absent from the header row
     * @param  list<array{row_number: int, cells: array<string, mixed>}>  $rows  1-indexed spreadsheet rows starting at row 2
     */
    public function __construct(
        public array $presentColumns,
        public array $missingRequiredColumns,
        public array $rows,
    ) {}
}
