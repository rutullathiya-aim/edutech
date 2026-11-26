<?php

namespace App\Support;

class LikePattern
{
    /**
     * Build a database-portable LIKE substring pattern escaping wildcard characters with '!'.
     */
    public static function escaped(?string $term): ?string
    {
        if ($term === null) {
            return null;
        }

        $trimmed = trim($term);

        if ($trimmed === '') {
            return null;
        }

        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $trimmed).'%';
    }
}
