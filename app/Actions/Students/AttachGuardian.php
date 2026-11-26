<?php

namespace App\Actions\Students;

use App\Actions\Students\Concerns\InteractsWithStudentRecords;
use App\Concerns\StudentValidationRules;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttachGuardian
{
    use InteractsWithStudentRecords, StudentValidationRules;

    /**
     * Link an existing guardian or create and link a new guardian to a student within the current school.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Student $student, array $data): StudentGuardian
    {
        $school = CurrentSchool::getOrFail();
        $normalized = $this->normalizeGuardianData($data);

        return DB::transaction(function () use ($actor, $student, $school, $normalized): StudentGuardian {
            $lockedStudent = $this->lockAndAuthorizeStudent($actor, $student);

            $existingLinks = StudentGuardian::query()
                ->where('student_id', $lockedStudent->id)
                ->lockForUpdate()
                ->get();

            if ($existingLinks->count() >= 3) {
                throw ValidationException::withMessages([
                    'guardians' => __('A student can have at most 3 linked guardians.'),
                ]);
            }

            $validated = Validator::make(
                $normalized,
                $this->guardianLinkRules($school->id),
            )->validate();

            $guardianId = $validated['guardian_id'] ?? null;

            if (is_string($guardianId) && $guardianId !== '') {
                $guardian = Guardian::query()
                    ->whereKey($guardianId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($existingLinks->contains('guardian_id', $guardian->id)) {
                    throw ValidationException::withMessages([
                        'guardian_id' => __('This guardian is already linked to the student.'),
                    ]);
                }
            } else {
                $guardian = Guardian::create([
                    'name' => $validated['name'],
                    'mobile' => $validated['mobile'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'occupation' => $validated['occupation'] ?? null,
                ]);
            }

            $existingHasMobile = $existingLinks->isNotEmpty()
                && Guardian::query()
                    ->whereIn('id', $existingLinks->pluck('guardian_id')->all())
                    ->whereNotNull('mobile')
                    ->exists();

            if ($guardian->mobile === null && ! $existingHasMobile) {
                throw ValidationException::withMessages([
                    'mobile' => __('At least one guardian mobile number is required.'),
                ]);
            }

            $isPrimary = (bool) ($validated['is_primary'] ?? false);

            if ($existingLinks->isEmpty() && ! $isPrimary) {
                throw ValidationException::withMessages([
                    'is_primary' => __('The student\'s first guardian must be marked as primary contact.'),
                ]);
            }

            if ($isPrimary) {
                foreach ($existingLinks->where('is_primary', true) as $currentPrimary) {
                    $currentPrimary->update(['is_primary' => false]);
                }
            }

            return StudentGuardian::create([
                'student_id' => $lockedStudent->id,
                'guardian_id' => $guardian->id,
                'relationship' => $validated['relationship'],
                'is_primary' => $isPrimary,
            ])->load('guardian');
        });
    }
}
