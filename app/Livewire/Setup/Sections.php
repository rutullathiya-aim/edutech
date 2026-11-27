<?php

namespace App\Livewire\Setup;

use App\Actions\Attendance\AssignClassTeacher;
use App\Concerns\SectionValidationRules;
use App\Enums\SchoolRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Membership;
use App\Models\Section;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, AcademicYear> $academicYears
 * @property-read AcademicYear|null $selectedAcademicYear
 * @property-read bool $isSelectedYearClosed
 * @property-read Collection<int, Grade> $activeGrades
 * @property-read Collection<int, Grade> $gradeGroups
 * @property-read bool $canManage
 * @property-read bool $canAssignClassTeacher
 * @property-read Collection<int, Membership> $activeClassTeacherCandidates
 */
#[Title('Sections')]
class Sections extends Component
{
    use SectionValidationRules;

    #[Locked]
    public ?string $editingId = null;

    #[Locked]
    public ?string $deletingId = null;

    public string $academic_year_id = '';

    public string $grade_id = '';

    public string $name = '';

    public int|string|null $capacity = '';

    public ?string $room = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    /**
     * Authorize access and default the selector to the school's current academic year.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Section::class);

        $years = $this->academicYears();
        $defaultYear = $years->firstWhere('is_current', true) ?? $years->first();

        $this->academic_year_id = $defaultYear instanceof AcademicYear ? $defaultYear->id : '';
    }

    /**
     * Get the current school's academic years for the year selector.
     *
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get the currently selected academic year within the current school.
     */
    #[Computed]
    public function selectedAcademicYear(): ?AcademicYear
    {
        if ($this->academic_year_id === '') {
            return null;
        }

        return $this->academicYears()->firstWhere('id', $this->academic_year_id);
    }

    /**
     * Determine whether the selected academic year is closed (read-only).
     */
    #[Computed]
    public function isSelectedYearClosed(): bool
    {
        return $this->selectedAcademicYear()?->isClosed() ?? false;
    }

    /**
     * Get active grades ordered by sort_order for the section form dropdown.
     *
     * @return Collection<int, Grade>
     */
    #[Computed]
    public function activeGrades(): Collection
    {
        return Grade::query()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Get grades that have sections in the selected academic year, ordered by sort_order.
     *
     * @return Collection<int, Grade>
     */
    #[Computed]
    public function gradeGroups(): Collection
    {
        $selectedYear = $this->selectedAcademicYear();

        if (! $selectedYear instanceof AcademicYear) {
            return new Collection;
        }

        return Grade::query()
            ->ordered()
            ->whereHas('sections', fn ($query) => $query->where('academic_year_id', $selectedYear->id))
            ->with(['sections' => fn ($query) => $query
                ->where('academic_year_id', $selectedYear->id)
                ->with('classTeacher:id,name')
                ->orderBy('name')])
            ->get();
    }

    /**
     * Determine whether the current user can create, update, or delete sections.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Section::class) ?? false;
    }

    /**
     * Determine whether the current user can assign or unassign class teachers.
     */
    #[Computed]
    public function canAssignClassTeacher(): bool
    {
        return auth()->user()?->can('assignClassTeacher', Section::class) ?? false;
    }

    /**
     * Load active staff members eligible to serve as class teachers in the current school.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function activeClassTeacherCandidates(): Collection
    {
        $schoolId = CurrentSchool::idOrFail();

        return Membership::query()
            ->where('school_id', $schoolId)
            ->active()
            ->withSchoolRole(SchoolRole::staffCases(), $schoolId)
            ->with(['user:id,name', 'user.roles'])
            ->get()
            ->sortBy(static function (Membership $membership): string {
                $role = $membership->staffRole();
                $priority = $role === SchoolRole::Teacher ? '0' : '1';
                $name = strtolower($membership->user->name);

                return "{$priority}|{$name}";
            })
            ->values();
    }

    /**
     * Determine whether a section's currently assigned class teacher no longer holds an active staff membership.
     */
    public function isAssignedTeacherDisabled(Section $section): bool
    {
        if ($section->class_teacher_user_id === null) {
            return false;
        }

        return ! $this->activeClassTeacherCandidates->contains('user_id', $section->class_teacher_user_id);
    }

    /**
     * Assign or unassign a class teacher for the given section using AssignClassTeacher.
     */
    public function assignClassTeacher(string $sectionId, string $teacherUserId, AssignClassTeacher $assignClassTeacher): void
    {
        $section = Section::findOrFail($sectionId);
        $this->authorize('assignClassTeacher', $section);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $trimmedTeacherId = trim($teacherUserId);
        $teacher = $trimmedTeacherId !== '' ? User::query()->findOrFail($trimmedTeacherId) : null;

        $assignClassTeacher->handle($actor, $section, $teacher);

        unset($this->gradeGroups, $this->activeClassTeacherCandidates);

        Flux::toast(
            variant: 'success',
            text: $teacher !== null ? __('Class teacher assigned.') : __('Class teacher unassigned.'),
        );
    }

    /**
     * Open the modal to create a new section in the selected open academic year.
     */
    public function openCreateModal(): void
    {
        $this->authorize('create', Section::class);

        $selectedYear = $this->selectedAcademicYear();

        if (! $selectedYear instanceof AcademicYear) {
            Flux::toast(variant: 'danger', text: __('Create an academic year before adding sections.'));

            return;
        }

        if ($selectedYear->isClosed()) {
            Flux::toast(variant: 'danger', text: __('A closed academic year is read-only.'));

            return;
        }

        $this->resetForm();
        $this->showFormModal = true;
    }

    /**
     * Open the modal to edit an existing section in the current school.
     */
    public function openEditModal(string $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        $this->authorize('update', $section);

        if ($section->academicYear->isClosed()) {
            Flux::toast(variant: 'danger', text: __('Sections in a closed academic year cannot be edited.'));

            return;
        }

        $this->resetForm();
        $this->editingId = $section->id;
        $this->academic_year_id = $section->academic_year_id;
        $this->grade_id = $section->grade_id;
        $this->name = $section->name;
        $this->capacity = $section->capacity ?? '';
        $this->room = $section->room ?? '';
        $this->showFormModal = true;
    }

    /**
     * Validate using the submitted year and grade IDs, then create or update the section.
     */
    public function save(): void
    {
        $this->academic_year_id = trim($this->academic_year_id);
        $this->grade_id = trim($this->grade_id);
        $this->name = trim($this->name);
        $this->room = filled($this->room) ? trim((string) $this->room) : null;
        $this->capacity = ($this->capacity === '' || $this->capacity === null)
            ? null
            : (is_string($this->capacity) ? trim($this->capacity) : $this->capacity);

        if ($this->editingId !== null) {
            $section = Section::findOrFail($this->editingId);
            $this->authorize('update', $section);

            if ($section->academicYear->isClosed()) {
                $this->addError('academic_year_id', __('Sections in a closed academic year cannot be edited.'));

                return;
            }

            // Academic year is immutable after creation so existing enrollments cannot shift years.
            $this->academic_year_id = $section->academic_year_id;

            /** @var array{academic_year_id: string, grade_id: string, name: string, capacity: int|string|null, room: string|null} $validated */
            $validated = $this->validate($this->sectionRules($this->academic_year_id, $this->grade_id, $section->id));
            $validated['capacity'] = $validated['capacity'] !== null ? (int) $validated['capacity'] : null;

            $section->update($validated);

            Flux::toast(variant: 'success', text: __('Section updated.'));
        } else {
            $this->authorize('create', Section::class);

            /** @var array{academic_year_id: string, grade_id: string, name: string, capacity: int|string|null, room: string|null} $validated */
            $validated = $this->validate($this->sectionRules($this->academic_year_id, $this->grade_id));
            $validated['capacity'] = $validated['capacity'] !== null ? (int) $validated['capacity'] : null;

            Section::create($validated);

            Flux::toast(variant: 'success', text: __('Section created.'));
        }

        $this->resetForm();
    }

    /**
     * Open the confirmation modal before deleting a section in an open academic year.
     */
    public function confirmDelete(string $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        $this->authorize('delete', $section);

        if ($section->academicYear->isClosed()) {
            Flux::toast(variant: 'danger', text: __('Sections in a closed academic year cannot be deleted.'));

            return;
        }

        $this->deletingId = $section->id;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the section targeted by the confirmation modal in an open academic year.
     */
    public function delete(): void
    {
        $this->authorize('delete', Section::class);

        if ($this->deletingId === null) {
            $this->resetDeleteModal();

            return;
        }

        $section = Section::findOrFail($this->deletingId);
        $this->authorize('delete', $section);

        if ($section->academicYear->isClosed()) {
            $this->resetDeleteModal();
            Flux::toast(variant: 'danger', text: __('Sections in a closed academic year cannot be deleted.'));

            return;
        }

        $section->delete();

        $this->resetDeleteModal();
        Flux::toast(variant: 'success', text: __('Section deleted.'));
    }

    /**
     * Cancel and reset the section form modal state.
     */
    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Cancel and reset the delete confirmation modal state.
     */
    public function cancelDelete(): void
    {
        $this->resetDeleteModal();
    }

    /**
     * Clear form inputs, editing target, and validation state.
     */
    public function resetForm(): void
    {
        $this->reset(['editingId', 'grade_id', 'name', 'capacity', 'room']);
        $this->showFormModal = false;
        $this->resetValidation();
    }

    /**
     * Clear the pending delete state.
     */
    public function resetDeleteModal(): void
    {
        $this->reset(['deletingId']);
        $this->showDeleteModal = false;
    }

    /**
     * Render the sections setup page.
     */
    public function render(): View
    {
        return view('livewire.setup.sections');
    }
}
