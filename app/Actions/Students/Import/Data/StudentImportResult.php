<?php

namespace App\Actions\Students\Import\Data;

readonly class StudentImportResult
{
    /**
     * Create a new student import result summary.
     */
    public function __construct(
        public string $academicYearId,
        public string $academicYearName,
        public int $importedCount,
        public int $guardiansCreatedCount,
        public int $guardiansReusedCount,
    ) {}
}
