<?php

namespace App\Enums;

enum GuardianRelationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Guardian = 'guardian';
    case Grandparent = 'grandparent';
    case Sibling = 'sibling';
    case Other = 'other';

    /**
     * Get the human-readable relationship label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Father => 'Father',
            self::Mother => 'Mother',
            self::Guardian => 'Guardian',
            self::Grandparent => 'Grandparent',
            self::Sibling => 'Sibling',
            self::Other => 'Other',
        };
    }
}
