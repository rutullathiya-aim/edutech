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

class SetPrimaryGuardian
{
    use InteractsWithStudentRecords;

    /**
     * Atomically mark a linked guardian as the student's sole primary contact.
     */
    public function handle(User $actor, Student $student, Guardian $guardian): StudentGuardian
    {
        CurrentSchool::getOrFail();

        return DB::transaction(function () use ($actor, $student, $guardian): StudentGuardian {
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

            if ($targetLink->isPrimary()) {
                return $targetLink->load('guardian');
            }

            if ($lockedGuardian->mobile === null) {
                throw ValidationException::withMessages([
                    'guardian_id' => __('The primary guardian must have a mobile number.'),
                ]);
            }

            foreach ($allLinks->where('is_primary', true) as $currentPrimary) {
                $currentPrimary->update(['is_primary' => false]);
            }

            $targetLink->update(['is_primary' => true]);

            return $targetLink->refresh()->load('guardian');
        });
    }
}
