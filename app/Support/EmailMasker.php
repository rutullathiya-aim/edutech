<?php

namespace App\Support;

class EmailMasker
{
    /**
     * Mask the local part of an email address for safe logging and UI display.
     */
    public static function mask(?string $email): string
    {
        if ($email === null || trim($email) === '') {
            return '***';
        }

        $parts = explode('@', trim($email), 2);

        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        $maskedLocal = strlen($local) <= 2
            ? substr($local, 0, 1).'***'
            : substr($local, 0, 2).'***';

        return $maskedLocal.'@'.$domain;
    }
}
