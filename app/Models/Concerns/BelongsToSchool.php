<?php

namespace App\Models\Concerns;

use App\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Trait for models that belong to a school tenant.
 *
 * Ensures tenant isolation by enforcing:
 * 1. The school relationship is defined.
 * 2. school_id is immutable after creation.
 *
 * @property string $school_id
 * @property-read School $school
 */
trait BelongsToSchool
{
    /**
     * Boot the BelongsToSchool trait.
     */
    protected static function bootBelongsToSchool(): void
    {
        static::updating(function (Model $model): void {
            if ($model->isDirty('school_id')) {
                throw new LogicException('The school_id cannot be changed once a record is created.');
            }
        });
    }

    /**
     * Get the school that owns the model.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
