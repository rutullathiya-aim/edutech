<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case Left = 'left';
    case PassedOut = 'passed_out';

    /**
     * Get the human-readable label for this student status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Left => 'Left',
            self::PassedOut => 'Passed out',
        };
    }
}
