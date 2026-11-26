<?php

namespace App\Livewire\Students;

use App\Actions\Students\AttachGuardian;
use App\Actions\Students\ChangeSection;
use App\Actions\Students\DetachGuardian;
use App\Actions\Students\SetPrimaryGuardian;
use App\Actions\Students\UpdateStudent;
use App\Enums\GuardianRelationship;
use App\Livewire\Students\Concerns\InteractsWithStudentScreens;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Support\IndianDate;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read School $school
 * @property-read bool $canViewSensitive
 * @property-read Collection<int, Grade> $activeGrades
 * @property-read Student $student
 * @property-read Enrollment|null $activeEnrollment
 * @property-read Collection<int, Section> $sections
 * @property-read list<array{id: string, name: string, mobile: string, label: string}> $siblingSuggestions
 */
#[Title('Edit student')]
class Edit extends Component
{
    use InteractsWithStudentScreens;

    #[Locked]
    public string $studentId;

    #[Locked]
    public ?string $currentEnrollmentId = null;

    #[Locked]
    public ?string $currentAcademicYearId = null;

    // Profile fields
    public string $admission_no = '';

    public string $admission_date = '';

    public string $name = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $nationality = 'Indian';

    public string $mother_tongue = '';

    public string $blood_group = '';

    public string $address_line = '';

    public string $city = '';

    public string $state = '';

    public string $pincode = '';

    public string $previous_school = '';

    public string $previous_tc_no = '';

    // Sensitive fields (hydrated and visible only when permitted)
    public string $social_category = '';

    public string $religion = '';

    public string $apaar_id = '';

    public string $pen = '';

    public string $aadhaar_last4 = '';

    // Section transfer fields
    public string $grade_id = '';

    public string $section_id = '';

    public string $roll_number = '';

    // Add guardian fields
    public string $new_guardian_name = '';

    public string $new_guardian_mobile = '';

    public string $new_guardian_email = '';

    public string $new_guardian_occupation = '';

    public string $new_guardian_relationship = GuardianRelationship::Mother->value;

    public bool $new_guardian_is_primary = false;

    #[Locked]
    public ?string $linkedGuardianId = null;

    #[Locked]
    public ?string $linkedGuardianLabel = null;

    // Replace-and-detach primary guardian modal state
    public bool $showDetachPrimaryModal = false;

    #[Locked]
    public ?string $detachingPrimaryGuardianId = null;

    public string $replacementPrimaryGuardianId = '';

    /**
     * Authorize editing the student and hydrate profile, enrollment, and sensitive fields cleanly.
     */
    public function mount(Student $student): void
    {
        $this->authorize('update', $student);

        $this->studentId = $student->id;

        $student->load([
            'enrollments' => static function ($query): void {
                $query->with([
                    'academicYear:id,school_id,name,starts_on,closed_at,is_current',
                    'section:id,school_id,grade_id,academic_year_id,name',
                ]);
            },
        ]);

        $this->admission_no = $student->admission_no;
        $this->admission_date = IndianDate::format($student->admission_date);
        $this->name = $student->name;
        $this->date_of_birth = IndianDate::format($student->date_of_birth);
        $this->gender = $student->gender->value;
        $this->nationality = $student->nationality;
        $this->mother_tongue = $student->mother_tongue ?? '';
        $this->blood_group = $student->blood_group->value ?? '';
        $this->address_line = $student->address_line ?? '';
        $this->city = $student->city ?? '';
        $this->state = $student->state->value ?? '';
        $this->pincode = $student->pincode ?? '';
        $this->previous_school = $student->previous_school ?? '';
        $this->previous_tc_no = $student->previous_tc_no ?? '';

        if ($this->canViewSensitive) {
            $this->social_category = $student->social_category->value ?? '';
            $this->religion = $student->religion ?? '';
            $this->apaar_id = $student->apaar_id ?? '';
            $this->pen = $student->pen ?? '';
            $this->aadhaar_last4 = $student->aadhaar_last4 ?? '';
        }

        $activeEnrollment = $student->enrollments
            ->filter(static fn (Enrollment $row): bool => $row->isActive())
            ->sortByDesc(static fn (Enrollment $row): string => ($row->academicYear->is_current ? '1' : '0').$row->academicYear->starts_on->format('Y-m-d'))
            ->first();

        if ($activeEnrollment instanceof Enrollment) {
            $this->currentEnrollmentId = $activeEnrollment->id;
            $this->currentAcademicYearId = $activeEnrollment->academic_year_id;
            $this->grade_id = $activeEnrollment->section->grade_id;
            $this->section_id = $activeEnrollment->section_id;
            $this->roll_number = $activeEnrollment->roll_number !== null ? (string) $activeEnrollment->roll_number : '';
        }
    }

    /**
     * Reset section selection when grade changes.
     */
    public function updatedGradeId(): void
    {
        $this->section_id = '';
        unset($this->sections);
    }

    /**
     * Invalidate sibling suggestions when the mobile field updates.
     */
    public function updatedNewGuardianMobile(): void
    {
        unset($this->siblingSuggestions);
    }

    /**
     * Load the editable student with guardian links and enrollments.
     */
    #[Computed]
    public function student(): Student
    {
        return Student::query()
            ->with([
                'studentGuardians' => static function ($query): void {
                    $query->orderByDesc('is_primary')
                        ->with('guardian:id,school_id,name,mobile,email,occupation');
                },
                'enrollments.academicYear:id,school_id,name,starts_on,closed_at,is_current',
            ])
            ->findOrFail($this->studentId);
    }

    /**
     * Load the student's active enrollment currently staged for section updates.
     */
    #[Computed]
    public function activeEnrollment(): ?Enrollment
    {
        if ($this->currentEnrollmentId === null) {
            return null;
        }

        return Enrollment::query()
            ->with(['academicYear', 'section.grade'])
            ->find($this->currentEnrollmentId);
    }

    /**
     * Get sections in the current enrollment's academic year and selected active grade.
     *
     * @return Collection<int, Section>
     */
    #[Computed]
    public function sections(): Collection
    {
        if ($this->currentAcademicYearId === null || $this->grade_id === '') {
            return new Collection;
        }

        return Section::query()
            ->where('academic_year_id', $this->currentAcademicYearId)
            ->where('grade_id', $this->grade_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Search matching guardians in this school for sibling linking by mobile number.
     *
     * @return list<array{id: string, name: string, mobile: string, label: string}>
     */
    #[Computed]
    public function siblingSuggestions(): array
    {
        if ($this->linkedGuardianId !== null) {
            return [];
        }

        $linkedIds = $this->student->studentGuardians->pluck('guardian_id')->all();

        return array_values(array_filter(
            $this->findSiblingGuardianSuggestions($this->new_guardian_mobile),
            static fn (array $suggestion): bool => ! in_array($suggestion['id'], $linkedIds, true),
        ));
    }

    /**
     * Save student profile updates via UpdateStudent.
     */
    public function saveProfile(UpdateStudent $updateStudent): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $student = Student::query()->findOrFail($this->studentId);
        $this->authorize('update', $student);

        $admissionDate = $this->parseUiDateOrFail('admission_date', $this->admission_date);
        $dateOfBirth = $this->parseUiDateOrFail('date_of_birth', $this->date_of_birth);

        $payload = [
            'admission_no' => $this->admission_no,
            'admission_date' => $admissionDate,
            'name' => $this->name,
            'date_of_birth' => $dateOfBirth,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'mother_tongue' => $this->mother_tongue,
            'blood_group' => $this->blood_group,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'previous_school' => $this->previous_school,
            'previous_tc_no' => $this->previous_tc_no,
        ];

        if ($this->canViewSensitive) {
            $payload['social_category'] = $this->social_category;
            $payload['religion'] = $this->religion;
            $payload['apaar_id'] = $this->apaar_id;
            $payload['pen'] = $this->pen;
            $payload['aadhaar_last4'] = $this->aadhaar_last4;
        }

        $updateStudent->handle($actor, $student, $payload);

        unset($this->student);
        Flux::toast(variant: 'success', text: __('Student profile updated.'));
    }

    /**
     * Move the active enrollment in place to another section in the same academic year.
     */
    public function saveSection(ChangeSection $changeSection): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $this->currentEnrollmentId === null) {
            return;
        }

        $enrollment = Enrollment::query()->findOrFail($this->currentEnrollmentId);
        $this->authorize('update', $enrollment);

        $this->validate([
            'section_id' => ['required', 'string'],
            'roll_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $section = Section::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->findOrFail($this->section_id);

        $rollNumber = trim($this->roll_number) !== '' ? (int) trim($this->roll_number) : null;

        $changeSection->handle($actor, $enrollment, $section, $rollNumber);

        unset($this->activeEnrollment, $this->student);
        Flux::toast(variant: 'success', text: __('Section placement updated.'));
    }

    /**
     * Select an existing school guardian via server-side school-scoped lookup for attaching as a sibling guardian.
     */
    public function linkExistingGuardian(string $guardianId): void
    {
        $student = Student::query()->findOrFail($this->studentId);
        $this->authorize('update', $student);

        $guardian = Guardian::query()->whereKey($guardianId)->firstOrFail();

        $this->linkedGuardianId = $guardian->id;
        $this->linkedGuardianLabel = $guardian->name.($guardian->mobile !== null ? " ({$guardian->mobile})" : '');
        $this->new_guardian_name = $guardian->name;
        $this->new_guardian_mobile = $guardian->mobile ?? '';
        $this->new_guardian_email = $guardian->email ?? '';
        $this->new_guardian_occupation = $guardian->occupation ?? '';

        unset($this->siblingSuggestions);
    }

    /**
     * Clear the locked sibling guardian link to enter a brand-new guardian instead.
     */
    public function unlinkExistingGuardian(): void
    {
        $this->linkedGuardianId = null;
        $this->linkedGuardianLabel = null;
        $this->new_guardian_name = '';
        $this->new_guardian_email = '';
        $this->new_guardian_occupation = '';

        unset($this->siblingSuggestions);
    }

    /**
     * Attach a new or linked existing guardian to the student via AttachGuardian.
     */
    public function attachGuardian(AttachGuardian $attachGuardian): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $student = Student::query()->findOrFail($this->studentId);
        $this->authorize('update', $student);

        $attachGuardian->handle($actor, $student, [
            'guardian_id' => $this->linkedGuardianId,
            'name' => $this->new_guardian_name,
            'mobile' => $this->new_guardian_mobile,
            'email' => $this->new_guardian_email,
            'occupation' => $this->new_guardian_occupation,
            'relationship' => $this->new_guardian_relationship,
            'is_primary' => $this->new_guardian_is_primary,
        ]);

        $this->reset([
            'new_guardian_name',
            'new_guardian_mobile',
            'new_guardian_email',
            'new_guardian_occupation',
            'new_guardian_is_primary',
            'linkedGuardianId',
            'linkedGuardianLabel',
        ]);
        $this->new_guardian_relationship = GuardianRelationship::Mother->value;
        unset($this->student, $this->siblingSuggestions);

        Flux::toast(variant: 'success', text: __('Guardian linked.'));
    }

    /**
     * Promote another linked guardian to primary contact via SetPrimaryGuardian.
     */
    public function setPrimaryGuardian(string $guardianId, SetPrimaryGuardian $setPrimaryGuardian): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $student = Student::query()->findOrFail($this->studentId);
        $guardian = Guardian::query()->findOrFail($guardianId);

        $setPrimaryGuardian->handle($actor, $student, $guardian);

        unset($this->student);
        Flux::toast(variant: 'success', text: __('Primary guardian updated.'));
    }

    /**
     * Detach a non-primary guardian link via DetachGuardian.
     */
    public function detachGuardian(string $guardianId, DetachGuardian $detachGuardian): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $student = Student::query()->findOrFail($this->studentId);
        $guardian = Guardian::query()->findOrFail($guardianId);

        $detachGuardian->handle($actor, $student, $guardian);

        unset($this->student, $this->siblingSuggestions);
        Flux::toast(variant: 'success', text: __('Guardian removed.'));
    }

    /**
     * Open confirmation modal to choose a replacement primary before detaching the current primary guardian.
     */
    public function openDetachPrimaryModal(string $guardianId): void
    {
        $student = $this->student;
        $this->authorize('update', $student);

        $this->detachingPrimaryGuardianId = $guardianId;
        $firstEligibleReplacement = $student->studentGuardians
            ->first(static fn ($link): bool => $link->guardian_id !== $guardianId && $link->guardian->mobile !== null);

        $this->replacementPrimaryGuardianId = $firstEligibleReplacement !== null ? $firstEligibleReplacement->guardian_id : '';
        $this->showDetachPrimaryModal = true;
    }

    /**
     * Replace the primary contact and detach the previous primary guardian atomically.
     */
    public function confirmDetachPrimary(DetachGuardian $detachGuardian): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $this->detachingPrimaryGuardianId === null) {
            return;
        }

        $student = Student::query()->findOrFail($this->studentId);
        $guardian = Guardian::query()->findOrFail($this->detachingPrimaryGuardianId);
        $replacement = $this->replacementPrimaryGuardianId !== ''
            ? Guardian::query()->findOrFail($this->replacementPrimaryGuardianId)
            : null;

        $detachGuardian->handle($actor, $student, $guardian, $replacement);

        $this->reset(['showDetachPrimaryModal', 'detachingPrimaryGuardianId', 'replacementPrimaryGuardianId']);
        unset($this->student, $this->siblingSuggestions);
        Flux::toast(variant: 'success', text: __('Primary guardian replaced and previous link removed.'));
    }

    /**
     * Render the edit student view.
     */
    public function render(): View
    {
        return view('livewire.students.edit');
    }
}
