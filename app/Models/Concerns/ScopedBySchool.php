<?php

namespace App\Models\Concerns;

use App\Models\Scopes\SchoolScope;
use App\Tenant\CurrentSchool;
use LogicException;

/**
 * Trait for models that are automatically scoped to the current school tenant.
 *
 * Enforces fail-closed query isolation via SchoolScope and write isolation during creation:
 * 1. Attaches SchoolScope (adding school_id = CurrentSchool::idOrFail() to every query).
 * 2. When creating a record:
 *    - If school_id is empty and a current school is active, auto-fills it from CurrentSchool.
 *    - If school_id is provided but differs from the current school, refuses cross-school writes.
 *    - If no current school is active (e.g. seeds, commands), requires an explicit school_id.
 *
 * @property string|null $school_id
 */
trait ScopedBySchool
{
    /**
     * Boot the ScopedBySchool trait.
     */
    protected static function bootScopedBySchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function (self $model): void {
            if (CurrentSchool::check()) {
                $currentSchoolId = CurrentSchool::idOrFail();

                if (empty($model->school_id)) {
                    $model->school_id = $currentSchoolId;
                } elseif ($model->school_id !== $currentSchoolId) {
                    throw new LogicException("Cross-school write refused: cannot assign school_id '{$model->school_id}' when current tenant is '{$currentSchoolId}'.");
                }
            } else {
                if (empty($model->school_id)) {
                    throw new LogicException('A school_id must be provided when creating a school-scoped model without an active tenant context.');
                }
            }
        });
    }
}
