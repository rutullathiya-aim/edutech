<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case HalfDay = 'half_day';
    case Leave = 'leave';

    /**
     * Get the human-readable label for this attendance status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Late => 'Late',
            self::HalfDay => 'Half day',
            self::Leave => 'Leave',
        };
    }

    /**
     * Get the compact register pill label (P/A/L/H/LV) for mobile attendance buttons and exports.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::Absent => 'A',
            self::Late => 'L',
            self::HalfDay => 'H',
            self::Leave => 'LV',
        };
    }

    /**
     * Get the weight toward attended days for CBSE attendance percentage calculation.
     */
    public function presenceWeight(): float
    {
        return match ($this) {
            self::Present, self::Late => 1.0,
            self::HalfDay => 0.5,
            self::Absent, self::Leave => 0.0,
        };
    }

    /**
     * Determine whether this status contributes positively to attended days.
     */
    public function countsAsPresent(): bool
    {
        return $this->presenceWeight() > 0.0;
    }
}
