<?php

namespace App\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Transgender = 'transgender';

    /**
     * Get the human-readable UDISE+ label for this gender.
     */
    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Transgender => 'Transgender',
        };
    }
}
