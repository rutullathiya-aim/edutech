<?php

namespace App\Actions\Students;

use App\Models\Student;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\Gate;

class SuggestAdmissionNumber
{
    /**
     * Suggest the next sequential admission number for the current school based on its latest admission number or a seed.
     */
    public function handle(User $actor, ?string $seedAdmissionNo = null): ?string
    {
        $school = CurrentSchool::getOrFail();

        Gate::forUser($actor)->authorize('create', Student::class);

        $baseAdmissionNo = $seedAdmissionNo !== null && trim($seedAdmissionNo) !== ''
            ? trim($seedAdmissionNo)
            : Student::query()
                ->latest('created_at')
                ->latest('id')
                ->value('admission_no');

        if (! is_string($baseAdmissionNo) || trim($baseAdmissionNo) === '') {
            $year = now($school->timezone ?: 'Asia/Kolkata')->format('Y');

            return $this->nextAvailableCandidate("ADM-{$year}-", 1, 4);
        }

        if (preg_match('/^(.*?)(\d+)$/', trim($baseAdmissionNo), $matches) !== 1) {
            if ($seedAdmissionNo !== null) {
                return $this->handle($actor);
            }

            return null;
        }

        $prefix = $matches[1];
        $digits = $matches[2];
        $padWidth = strlen($digits);
        $nextNumber = (int) $digits + 1;

        return $this->nextAvailableCandidate($prefix, $nextNumber, $padWidth);
    }

    /**
     * Find the first unused formatted candidate starting from the given number.
     */
    private function nextAvailableCandidate(string $prefix, int $startNumber, int $padWidth): ?string
    {
        $number = $startNumber;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $suffix = str_pad((string) $number, $padWidth, '0', STR_PAD_LEFT);
            $candidate = $prefix.$suffix;

            if (strlen($candidate) > 30) {
                return null;
            }

            if (! Student::query()->where('admission_no', $candidate)->exists()) {
                return $candidate;
            }

            $number++;
        }

        return null;
    }
}
