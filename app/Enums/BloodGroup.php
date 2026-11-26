<?php

namespace App\Enums;

enum BloodGroup: string
{
    case APos = 'A+';
    case ANeg = 'A-';
    case BPos = 'B+';
    case BNeg = 'B-';
    case AbPos = 'AB+';
    case AbNeg = 'AB-';
    case OPos = 'O+';
    case ONeg = 'O-';

    /**
     * Get the human-readable blood group label.
     */
    public function label(): string
    {
        return $this->value;
    }
}
