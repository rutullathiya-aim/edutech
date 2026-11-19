<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $event
 * @property string|null $causer_type
 * @property string|null $causer_id
 * @property string|null $school_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Collection<string, mixed>|null $properties
 * @property array<string, mixed>|null $attribute_changes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read School|null $school
 */
class Activity extends SpatieActivity
{
    use HasUlids;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Activity $activity): void {
            $schoolId = null;

            if ($activity->subject instanceof School) {
                $schoolId = $activity->subject->id;
            } elseif ($activity->subject && isset($activity->subject->school_id)) {
                $schoolId = $activity->subject->school_id;
            } elseif (Context::has('school_id')) {
                $schoolId = Context::get('school_id');
            }

            $activity->school_id = $schoolId;

            if (app()->bound('request')) {
                $request = request();
                if ($activity->ip_address === null) {
                    $activity->ip_address = $request->ip();
                }
                if ($activity->user_agent === null) {
                    $activity->user_agent = $request->userAgent();
                }
            }
        });

        static::updating(function (): void {
            throw new LogicException('Activity logs are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Activity logs are append-only and cannot be deleted.');
        });
    }

    /**
     * Get the school associated with the activity.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
