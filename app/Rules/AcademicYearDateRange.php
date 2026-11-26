<?php

namespace App\Rules;

use App\Models\AcademicYear;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

class AcademicYearDateRange implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(
        private readonly ?string $academicYearId = null,
    ) {}

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rawStart = $this->data['starts_on'] ?? null;

        if (! is_string($rawStart) || ! is_string($value) || trim($rawStart) === '' || trim($value) === '') {
            return;
        }

        try {
            $startDate = CarbonImmutable::parse($rawStart)->startOfDay();
            $endDate = CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($endDate->lessThanOrEqualTo($startDate)) {
            return;
        }

        if ($endDate->greaterThan($startDate->addMonthsNoOverflow(18))) {
            $fail('The academic year cannot be longer than 18 months.');

            return;
        }

        $overlaps = AcademicYear::query()
            ->where('school_id', CurrentSchool::idOrFail())
            ->when(
                $this->academicYearId !== null,
                fn (Builder $query) => $query->where('id', '!=', $this->academicYearId),
            )
            ->whereDate('starts_on', '<=', $endDate->toDateString())
            ->whereDate('ends_on', '>=', $startDate->toDateString())
            ->exists();

        if ($overlaps) {
            $fail('The academic year dates overlap with an existing academic year.');
        }
    }
}
