<?php

namespace App\Actions\Attendance\Data;

use App\Enums\AttendanceStatus;

readonly class ResolvedRegisterRow
{
    public function __construct(
        public string $enrollmentId,
        public string $studentId,
        public ?int $rollNumber,
        public string $studentName,
        public ?AttendanceStatus $status,
        public ?string $remark,
    ) {}
}
