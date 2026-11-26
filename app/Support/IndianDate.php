<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class IndianDate
{
    /**
     * Strictly parse an Indian dd/mm/yyyy date string into a CarbonImmutable instance.
     */
    public static function parseStrict(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $trimmed) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!d/m/Y', $trimmed);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('d/m/Y') !== $trimmed) {
            return null;
        }

        return $parsed;
    }

    /**
     * Parse a dd/mm/yyyy UI date string and return Y-m-d for database validation and storage.
     */
    public static function toDatabaseDate(?string $value): ?string
    {
        return static::parseStrict($value)?->toDateString();
    }

    /**
     * Format a date instance as dd/mm/yyyy for UI inputs.
     */
    public static function format(?CarbonInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }
}
