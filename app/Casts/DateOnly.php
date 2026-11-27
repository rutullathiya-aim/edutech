<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Store calendar dates consistently as Y-m-d strings across SQLite and MySQL while returning CarbonImmutable instances.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonInterface|string|null>
 */
class DateOnly implements CastsAttributes
{
    /**
     * Cast the stored date value to a start-of-day CarbonImmutable instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }

    /**
     * Prepare the date value for persistence as a Y-m-d string.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
