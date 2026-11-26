<?php

namespace App\Enums;

enum SocialCategory: string
{
    case General = 'general';
    case Obc = 'obc';
    case Sc = 'sc';
    case St = 'st';

    /**
     * Get the human-readable label for this social category.
     */
    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Obc => 'OBC',
            self::Sc => 'SC',
            self::St => 'ST',
        };
    }
}
