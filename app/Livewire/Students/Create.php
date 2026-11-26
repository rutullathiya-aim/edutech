<?php

namespace App\Livewire\Students;

use App\Actions\Students\AdmitStudent;
use App\Actions\Students\SuggestAdmissionNumber;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Livewire\Students\Concerns\InteractsWithStudentScreens;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
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
 * @property-read Collection<int, AcademicYear> $openAcademicYears
 * @property-read Collection<int, Section> $sections
 * @property-read array<int, list<array{id: string, name: string, mobile: string, label: string}>> $siblingSuggestions
 */
#[Title('Admit student')]
class Create extends Component
{
    use InteractsWithStudentScreens;

    // Group 1: Student details
    public string $admission_no = '';

    public string $admission_date = '';

    public string $name = '';

    public string $date_of_birth = '';

    public string $gender = Gender::Male->value;

    public string $nationality = 'Indian';

    public string $mother_tongue = '';

    public string $blood_group = '';

    // Group 2: Placement
    public string $academic_year_id = '';

    public string $grade_id = '';

    public string $section_id = '';

    public string $roll_number = '';

    // Group 3: Guardians (1 to 3 repeatable rows) + locked server-verified existing guardian IDs
    /**
     * @var list<array{name: string, mobile: string, email: string, occupation: string, relationship: string, is_primary: bool}>
     */
    public array $guardians = [];

    /**
     * @var list<string|null>
     */
    #[Locked]
    public array $linkedGuardianIds = [];

    /**
     * @var list<string|null>
     */
    #[Locked]
    public array $linkedGuardianLabels = [];

    // Group 4: Address & previous school
    public string $address_line = '';

    public string $city = '';

    public string $state = '';

    public string $pincode = '';

    public string $previous_school = '';

    public string $previous_tc_no = '';

    // Group 5: Sensitive IDs & consent
    public string $social_category = '';

    public string $religion = '';

    public string $apaar_id = '';

    public string $pen = '';

    public string $aadhaar_last4 = '';

    public bool $consent_confirmed = false;

    /**
     * Authorize access and prepare defaults (admission number suggestion, current date, first guardian row).
     */
    public function mount(SuggestAdmissionNumber $suggestAdmissionNumber): void
    {
        $this->authorize('create', Student::class);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $school = $this->school;
        $timezone = $school->timezone ?: 'Asia/Kolkata';

        $this->admission_no = $suggestAdmissionNumber->handle($actor) ?? '';
        $this->admission_date = now($timezone)->format('d/m/Y');

        $openYears = $this->openAcademicYears;
        $defaultYear = $openYears->firstWhere('is_current', true) ?? $openYears->first();
        $this->academic_year_id = $defaultYear !== null ? $defaultYear->id : '';

        $this->guardians = [
            [
                'name' => '',
                'mobile' => '',
                'email' => '',
                'occupation' => '',
                'relationship' => GuardianRelationship::Father->value,
                'is_primary' => true,
            ],
        ];
        $this->linkedGuardianIds = [null];
        $this->linkedGuardianLabels = [null];
    }

    /**
     * Reset section selection when the academic year changes.
     */
    public function updatedAcademicYearId(): void
    {
        $this->section_id = '';
        unset($this->sections);
    }

    /**
     * Reset section selection when the grade changes.
     */
    public function updatedGradeId(): void
    {
        $this->section_id = '';
        unset($this->sections);
    }

    /**
     * Recompute sibling suggestions when guardian fields update.
     */
    public function updatedGuardians(): void
    {
        unset($this->siblingSuggestions);
    }

    /**
     * Get open (non-closed) academic years for enrollment placement.
     *
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function openAcademicYears(): Collection
    {
        return AcademicYear::query()
            ->whereNull('closed_at')
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get sections matching the selected open academic year and active grade.
     *
     * @return Collection<int, Section>
     */
    #[Computed]
    public function sections(): Collection
    {
        if ($this->academic_year_id === '' || $this->grade_id === '') {
            return new Collection;
        }

        return Section::query()
            ->where('academic_year_id', $this->academic_year_id)
            ->where('grade_id', $this->grade_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Compute matching school guardians for unlinked rows based on normalized mobile input.
     *
     * @return array<int, list<array{id: string, name: string, mobile: string, label: string}>>
     */
    #[Computed]
    public function siblingSuggestions(): array
    {
        $suggestionsByRow = [];

        foreach ($this->guardians as $index => $row) {
            if (($this->linkedGuardianIds[$index] ?? null) !== null) {
                $suggestionsByRow[$index] = [];

                continue;
            }

            $suggestionsByRow[$index] = $this->findSiblingGuardianSuggestions($row['mobile']);
        }

        return $suggestionsByRow;
    }

    /**
     * Add an additional guardian row up to 3 guardians.
     */
    public function addGuardian(): void
    {
        if (count($this->guardians) >= 3) {
            return;
        }

        $this->guardians[] = [
            'name' => '',
            'mobile' => '',
            'email' => '',
            'occupation' => '',
            'relationship' => GuardianRelationship::Mother->value,
            'is_primary' => false,
        ];
        $this->linkedGuardianIds[] = null;
        $this->linkedGuardianLabels[] = null;
    }

    /**
     * Remove a guardian row while keeping at least one row and one primary selection.
     */
    public function removeGuardian(int $index): void
    {
        if (count($this->guardians) <= 1 || ! array_key_exists($index, $this->guardians)) {
            return;
        }

        $wasPrimary = $this->guardians[$index]['is_primary'];

        $guardians = $this->guardians;
        $linkedIds = $this->linkedGuardianIds;
        $linkedLabels = $this->linkedGuardianLabels;

        unset($guardians[$index], $linkedIds[$index], $linkedLabels[$index]);

        $guardians = array_values($guardians);

        if ($wasPrimary) {
            $guardians[0]['is_primary'] = true;
        }

        $this->guardians = $guardians;
        $this->linkedGuardianIds = array_values($linkedIds);
        $this->linkedGuardianLabels = array_values($linkedLabels);

        unset($this->siblingSuggestions);
    }

    /**
     * Select one guardian row as the student's primary contact.
     */
    public function setPrimaryGuardian(int $index): void
    {
        foreach ($this->guardians as $i => $row) {
            $this->guardians[$i]['is_primary'] = ($i === $index);
        }
    }

    /**
     * Link an existing school guardian (re-fetched via school scope) onto the specified guardian row.
     */
    public function linkSiblingGuardian(int $index, string $guardianId): void
    {
        $this->authorize('create', Student::class);

        if (! array_key_exists($index, $this->guardians)) {
            return;
        }

        $guardian = Guardian::query()
            ->whereKey($guardianId)
            ->firstOrFail();

        $linkedIds = $this->linkedGuardianIds;
        $linkedLabels = $this->linkedGuardianLabels;
        $linkedIds[$index] = $guardian->id;
        $linkedLabels[$index] = $guardian->name.($guardian->mobile !== null ? " ({$guardian->mobile})" : '');

        $this->linkedGuardianIds = array_values($linkedIds);
        $this->linkedGuardianLabels = array_values($linkedLabels);
        $this->guardians[$index]['name'] = $guardian->name;
        $this->guardians[$index]['mobile'] = $guardian->mobile ?? '';
        $this->guardians[$index]['email'] = $guardian->email ?? '';
        $this->guardians[$index]['occupation'] = $guardian->occupation ?? '';

        unset($this->siblingSuggestions);
    }

    /**
     * Clear a linked sibling guardian so a new guardian record can be entered instead.
     */
    public function unlinkSiblingGuardian(int $index): void
    {
        if (! array_key_exists($index, $this->guardians)) {
            return;
        }

        $linkedIds = $this->linkedGuardianIds;
        $linkedLabels = $this->linkedGuardianLabels;
        $linkedIds[$index] = null;
        $linkedLabels[$index] = null;

        $this->linkedGuardianIds = array_values($linkedIds);
        $this->linkedGuardianLabels = array_values($linkedLabels);
        $this->guardians[$index]['name'] = '';
        $this->guardians[$index]['email'] = '';
        $this->guardians[$index]['occupation'] = '';

        unset($this->siblingSuggestions);
    }

    /**
     * Validate UI dates, submit admission atomically through AdmitStudent, and redirect to the new profile.
     */
    public function save(AdmitStudent $admitStudent): void
    {
        $this->authorize('create', Student::class);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $admissionDate = $this->parseUiDateOrFail('admission_date', $this->admission_date);
        $dateOfBirth = $this->parseUiDateOrFail('date_of_birth', $this->date_of_birth);

        $studentPayload = [
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
            $studentPayload['social_category'] = $this->social_category;
            $studentPayload['religion'] = $this->religion;
            $studentPayload['apaar_id'] = $this->apaar_id;
            $studentPayload['pen'] = $this->pen;
            $studentPayload['aadhaar_last4'] = $this->aadhaar_last4;
        }

        $guardiansPayload = [];

        foreach ($this->guardians as $index => $row) {
            $guardiansPayload[] = [
                'guardian_id' => $this->linkedGuardianIds[$index] ?? null,
                'name' => $row['name'],
                'mobile' => $row['mobile'],
                'email' => $row['email'],
                'occupation' => $row['occupation'],
                'relationship' => $row['relationship'],
                'is_primary' => $row['is_primary'],
            ];
        }

        $student = $admitStudent->handle(
            $actor,
            $studentPayload,
            $guardiansPayload,
            [
                'academic_year_id' => $this->academic_year_id,
                'grade_id' => $this->grade_id,
                'section_id' => $this->section_id,
                'roll_number' => $this->roll_number,
                'enrolled_on' => $admissionDate,
            ],
            $this->consent_confirmed,
        );

        Flux::toast(variant: 'success', text: __('Student admitted cleanly.'));

        $this->redirect(
            route('students.show', ['school' => $this->school->id, 'student' => $student->id]),
            navigate: true,
        );
    }

    /**
     * Render the full-page admission form.
     */
    public function render(): View
    {
        return view('livewire.students.create');
    }
}
