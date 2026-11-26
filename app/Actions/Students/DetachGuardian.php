<?php

namespace App\Actions\Students;

use App\Actions\Students\Concerns\InteractsWithStudentRecords;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DetachGuardian
{
    use InteractsWithStudentRecords;

    /**
     * Remove a guardian link from a student while protecting the last guardian, primary contact, and mobile requirement.
     */
    public function handle(
        User $actor,
        Student $student,
        Guardian $guardian,
        ?Guardian $newPrimaryGuardian = null,
    ): void {
        CurrentSchool::getOrFail();

        DB::transaction(function () use ($actor, $student, $guardian, $newPrimaryGuardian): void {
            $lockedStudent = $this->lockAndAuthorizeStudent($actor, $student);

            $lockedGuardian = Guardian::query()
                ->whereKey($guardian->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allLinks = StudentGuardian::query()
                ->where('student_id', $lockedStudent->id)
                ->lockForUpdate()
                ->get();

            $targetLink = $allLinks->firstWhere('guardian_id', $lockedGuardian->id);

            if (! $targetLink instanceof StudentGuardian) {
                throw ValidationException::withMessages([
                    'guardian_id' => __('This guardian is not linked to the student.'),
                ]);
            }

            if ($allLinks->count() <= 1) {
                throw ValidationException::withMessages([
                    'guardian_id' => __('Cannot remove the student\'s last guardian.'),
                ]);
            }

            $remainingLinks = $allLinks
                ->reject(fn (StudentGuardian $link): bool => $link->id === $targetLink->id)
                ->values();

            $remainingHasMobile = Guardian::query()
                ->whereIn('id', $remainingLinks->pluck('guardian_id')->all())
                ->whereNotNull('mobile')
                ->exists();

            if (! $remainingHasMobile) {
                throw ValidationException::withMessages([
                    'guardian_id' => __('At least one remaining guardian must have a mobile number.'),
                ]);
            }

            if ($targetLink->isPrimary()) {
                if (! $newPrimaryGuardian instanceof Guardian) {
                    throw ValidationException::withMessages([
                        'new_primary_guardian_id' => __('Choose a new primary guardian before removing the current primary contact.'),
                    ]);
                }

                $lockedReplacementGuardian = Guardian::query()
                    ->whereKey($newPrimaryGuardian->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $replacementLink = $remainingLinks->firstWhere('guardian_id', $lockedReplacementGuardian->id);

                if (! $replacementLink instanceof StudentGuardian) {
                    throw ValidationException::withMessages([
                        'new_primary_guardian_id' => __('The selected primary guardian must already be linked to this student.'),
                    ]);
                }

                if ($lockedReplacementGuardian->mobile === null) {
                    throw ValidationException::withMessages([
                        'new_primary_guardian_id' => __('The replacement primary guardian must have a mobile number.'),
                    ]);
                }

                $targetLink->delete();
                $replacementLink->update(['is_primary' => true]);

                return;
            }

            $targetLink->delete();
        });
    }
}
