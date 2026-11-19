<?php

namespace App\Tenant;

use App\Exceptions\NoCurrentSchoolException;
use App\Models\School;
use Illuminate\Support\Facades\Context;

class CurrentSchool
{
    protected const CONTEXT_KEY = 'tenant_school';

    protected const CONTEXT_ID_KEY = 'tenant_school_id';

    /**
     * Set the current school tenant.
     */
    public static function set(School $school): void
    {
        Context::addHidden(self::CONTEXT_KEY, $school);
        Context::addHidden(self::CONTEXT_ID_KEY, $school->id);
    }

    /**
     * Get the current school tenant instance.
     */
    public static function get(): ?School
    {
        $school = Context::getHidden(self::CONTEXT_KEY);

        return $school instanceof School ? $school : null;
    }

    /**
     * Get the current school tenant instance or throw if none is set.
     *
     * @throws NoCurrentSchoolException
     */
    public static function getOrFail(): School
    {
        $school = static::get();

        if ($school === null) {
            throw new NoCurrentSchoolException;
        }

        return $school;
    }

    /**
     * Get the ID of the current school tenant.
     */
    public static function id(): ?string
    {
        /** @var string|null $id */
        $id = Context::getHidden(self::CONTEXT_ID_KEY);

        return $id ?? static::get()?->id;
    }

    /**
     * Get the ID of the current school tenant or throw if none is set.
     *
     * @throws NoCurrentSchoolException
     */
    public static function idOrFail(): string
    {
        $id = static::id();

        if ($id === null) {
            throw new NoCurrentSchoolException;
        }

        return $id;
    }

    /**
     * Determine if a current school tenant is set.
     */
    public static function check(): bool
    {
        return static::id() !== null;
    }

    /**
     * Clear the current school tenant from context.
     */
    public static function forget(): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);
        Context::forgetHidden(self::CONTEXT_ID_KEY);
    }
}
