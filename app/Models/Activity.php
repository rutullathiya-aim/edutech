<?php

namespace App\Models;

use App\Tenant\CurrentSchool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
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
 * @property Collection<string, mixed>|null $attribute_changes
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
            } elseif (CurrentSchool::check()) {
                $schoolId = CurrentSchool::id();
            }

            $activity->school_id = $schoolId;

            if ($activity->subject instanceof Student) {
                $sensitiveFields = $activity->subject->consumeSensitiveAuditFields();

                if ($sensitiveFields !== []) {
                    $activity->properties = ($activity->properties ?? collect())
                        ->put('sensitive_fields_changed', $sensitiveFields);
                }
            }

            if (app()->bound('request')) {
                $request = request();
                $activity->ip_address = $request->ip();
                $activity->user_agent = $request->userAgent() !== null
                    ? substr($request->userAgent(), 0, 500)
                    : null;
            } elseif ($activity->user_agent !== null) {
                $activity->user_agent = substr($activity->user_agent, 0, 500);
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
