<?php

namespace App\Enums;

enum SubjectKind: string
{
    case Scholastic = 'scholastic';
    case CoScholastic = 'co_scholastic';

    /**
     * Get the human-readable label for the subject kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scholastic => 'Scholastic',
            self::CoScholastic => 'Co-Scholastic',
        };
    }
}
