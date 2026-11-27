<?php

namespace App\Actions\Attendance\Data;

use App\Models\AttendanceSession;
use Illuminate\Support\Collection;

readonly class ResolvedRegister
{
    /**
     * @param  Collection<int, ResolvedRegisterRow>  $rows
     */
    public function __construct(
        public ?AttendanceSession $session,
        public int $version,
        public Collection $rows,
    ) {}
}
