<?php

namespace App\Livewire\Setup;

use App\Actions\AcademicYears\MakeAcademicYearCurrent;
use App\Concerns\AcademicYearValidationRules;
use App\Models\AcademicYear;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Academic years')]
class AcademicYears extends Component
{
    use AcademicYearValidationRules;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public bool $showFormModal = false;

    /**
     * Authorize access to the academic years setup page.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', AcademicYear::class);
    }

    /**
     * Get the current school's academic years ordered newest first.
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
     * Determine whether the current user can create or modify academic years.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', AcademicYear::class) ?? false;
    }

    /**
     * Open the modal to create a new academic year.
     */
    public function openCreateModal(): void
    {
        $this->authorize('create', AcademicYear::class);

        $this->resetForm();
        $this->showFormModal = true;
    }

    /**
     * Open the modal to edit an existing open academic year in the current school.
     */
    public function openEditModal(string $academicYearId): void
    {
        $academicYear = AcademicYear::findOrFail($academicYearId);
        $this->authorize('update', $academicYear);

        if ($academicYear->isClosed()) {
            Flux::toast(variant: 'danger', text: __('A closed academic year cannot be edited.'));

            return;
        }

        $this->resetForm();
        $this->editingId = $academicYear->id;
        $this->name = $academicYear->name;
        $this->starts_on = $academicYear->starts_on->toDateString();
        $this->ends_on = $academicYear->ends_on->toDateString();
        $this->showFormModal = true;
    }

    /**
     * Validate and create or update the academic year.
     */
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->starts_on = trim($this->starts_on);
        $this->ends_on = trim($this->ends_on);

        if ($this->editingId !== null) {
            $academicYear = AcademicYear::findOrFail($this->editingId);
            $this->authorize('update', $academicYear);

            if ($academicYear->isClosed()) {
                $this->addError('name', __('A closed academic year cannot be edited.'));

                return;
            }

            /** @var array{name: string, starts_on: string, ends_on: string} $validated */
            $validated = $this->validate($this->academicYearRules($academicYear->id));

            $academicYear->update($validated);

            Flux::toast(variant: 'success', text: __('Academic year updated.'));
        } else {
            $this->authorize('create', AcademicYear::class);

            /** @var array{name: string, starts_on: string, ends_on: string} $validated */
            $validated = $this->validate($this->academicYearRules());

            AcademicYear::create($validated);

            Flux::toast(variant: 'success', text: __('Academic year created.'));
        }

        $this->resetForm();
    }

    /**
     * Mark the specified academic year as the current year for the current school.
     */
    public function makeCurrent(string $academicYearId, MakeAcademicYearCurrent $makeAcademicYearCurrent): void
    {
        $academicYear = AcademicYear::findOrFail($academicYearId);
        $this->authorize('makeCurrent', $academicYear);

        if ($academicYear->isClosed()) {
            Flux::toast(variant: 'danger', text: __('A closed academic year cannot be marked as current.'));

            return;
        }

        $makeAcademicYearCurrent->handle($academicYear);

        Flux::toast(variant: 'success', text: __(':name is now the current academic year.', ['name' => $academicYear->name]));
    }

    /**
     * Cancel and reset the modal form state.
     */
    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Clear form inputs, editing target, and validation state.
     */
    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'starts_on', 'ends_on']);
        $this->showFormModal = false;
        $this->resetValidation();
    }

    /**
     * Render the academic years setup page.
     */
    public function render(): View
    {
        return view('livewire.setup.academic-years');
    }
}
