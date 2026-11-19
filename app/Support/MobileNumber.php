<?php

namespace App\Support;

class MobileNumber
{
    /**
     * Regex for a valid Indian mobile number: +91 followed by 10 digits starting with 6, 7, 8, or 9.
     */
    public const string REGEX = '/^\+91[6-9]\d{9}$/';

    /**
     * Normalize an input mobile number to standard Indian format (+91 followed by 10 digits).
     *
     * Known valid Indian mobile patterns:
     * - 10 digits starting with 6, 7, 8, or 9 (e.g. "9876543210")
     * - 11 digits starting with 0 followed by 6, 7, 8, or 9 (e.g. "09876543210")
     * - 12 digits starting with 91 followed by 6, 7, 8, or 9 (e.g. "919876543210")
     * - 13 characters starting with +91 followed by 10 digits (e.g. "+91 98765 43210")
     *
     * Input that does not match one of these known patterns is returned trimmed as-is,
     * so that validation rules reject it rather than storing an invalid number.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $trimmed);

        if ($cleaned === null || $cleaned === '') {
            return $trimmed;
        }

        if (preg_match('/^[6-9]\d{9}$/', $cleaned) === 1) {
            return '+91'.$cleaned;
        }

        if (preg_match('/^0[6-9]\d{9}$/', $cleaned) === 1) {
            return '+91'.substr($cleaned, 1);
        }

        if (preg_match('/^91[6-9]\d{9}$/', $cleaned) === 1) {
            return '+'.$cleaned;
        }

        if (preg_match('/^\+91[6-9]\d{9}$/', $cleaned) === 1) {
            return $cleaned;
        }

        return $trimmed;
    }

    /**
     * Check if a given mobile number is a valid Indian mobile number.
     */
    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = static::normalize($value);

        return $normalized !== null && preg_match(static::REGEX, $normalized) === 1;
    }
}
