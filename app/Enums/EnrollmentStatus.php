<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Promoted = 'promoted';
    case Detained = 'detained';
    case Left = 'left';

    /**
     * Get the human-readable label for this enrollment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Promoted => 'Promoted',
            self::Detained => 'Detained',
            self::Left => 'Left',
        };
    }
}
