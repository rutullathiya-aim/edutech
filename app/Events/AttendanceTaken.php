<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once after transaction commit when daily attendance is recorded for a section for the first time.
 */
class AttendanceTaken
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Create a new event instance carrying only unexcused Absent enrollment IDs for downstream notifications.
     *
     * @param  list<string>  $absentEnrollmentIds
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $attendanceSessionId,
        public readonly array $absentEnrollmentIds,
    ) {}
}
