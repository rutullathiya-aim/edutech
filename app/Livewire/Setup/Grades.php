<?php

namespace App\Livewire\Setup;

use App\Actions\Grades\CreateStandardGrades;
use App\Concerns\GradeValidationRules;
use App\Models\Grade;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Grades')]
class Grades extends Component
{
    use GradeValidationRules;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public ?string $short_name = '';

    public int|string $sort_order = '';

    public bool $showFormModal = false;

    /**
     * Authorize access to the grades setup page.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Grade::class);
    }

    /**
     * Get all grades for the current school ordered by display/promotion sequence.
     *
     * @return Collection<int, Grade>
     */
    #[Computed]
    public function grades(): Collection
    {
        return Grade::query()
            ->ordered()
            ->get();
    }

    /**
     * Determine whether the current user can create, update, archive, or restore grades.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Grade::class) ?? false;
    }

    /**
     * Open the modal to create a new grade.
     */
    public function openCreateModal(): void
    {
        $this->authorize('create', Grade::class);

        $this->resetForm();
        $this->showFormModal = true;
    }

    /**
     * Open the modal to edit an existing grade in the current school.
     */
    public function openEditModal(string $gradeId): void
    {
        $grade = Grade::findOrFail($gradeId);
        $this->authorize('update', $grade);

        $this->resetForm();
        $this->editingId = $grade->id;
        $this->name = $grade->name;
        $this->short_name = $grade->short_name ?? '';
        $this->sort_order = $grade->sort_order;
        $this->showFormModal = true;
    }

    /**
     * Validate and create or update the grade.
     */
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->short_name = filled($this->short_name) ? trim((string) $this->short_name) : null;
        $this->sort_order = is_string($this->sort_order) ? trim($this->sort_order) : $this->sort_order;

        if ($this->editingId !== null) {
            $grade = Grade::findOrFail($this->editingId);
            $this->authorize('update', $grade);

            /** @var array{name: string, short_name: string|null, sort_order: int|string} $validated */
            $validated = $this->validate($this->gradeRules($grade->id));
            $validated['sort_order'] = (int) $validated['sort_order'];

            $grade->update($validated);

            Flux::toast(variant: 'success', text: __('Grade updated.'));
        } else {
            $this->authorize('create', Grade::class);

            /** @var array{name: string, short_name: string|null, sort_order: int|string} $validated */
            $validated = $this->validate($this->gradeRules());
            $validated['sort_order'] = (int) $validated['sort_order'];

            Grade::create($validated);

            Flux::toast(variant: 'success', text: __('Grade created.'));
        }

        $this->resetForm();
    }

    /**
     * Add missing standard K-12 grades (Nursery through Class 12) for the current school.
     */
    public function createStandardGrades(CreateStandardGrades $createStandardGrades): void
    {
        $this->authorize('create', Grade::class);

        $created = $createStandardGrades->handle();

        if ($created->isEmpty()) {
            Flux::toast(text: __('All standard grades are already set up.'));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            '{1} Added :count standard grade.|[2,*] Added :count standard grades.',
            $created->count(),
            ['count' => $created->count()],
        ));
    }

    /**
     * Archive an active grade in the current school.
     */
    public function archive(string $gradeId): void
    {
        $grade = Grade::findOrFail($gradeId);
        $this->authorize('archive', $grade);

        if (! $grade->isArchived()) {
            $grade->archived_at = now();
            $grade->save();

            Flux::toast(variant: 'success', text: __('Grade archived.'));
        }
    }

    /**
     * Restore a previously archived grade in the current school.
     */
    public function restore(string $gradeId): void
    {
        $grade = Grade::findOrFail($gradeId);
        $this->authorize('restore', $grade);

        if ($grade->isArchived()) {
            $grade->archived_at = null;
            $grade->save();

            Flux::toast(variant: 'success', text: __('Grade restored.'));
        }
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
        $this->reset(['editingId', 'name', 'short_name', 'sort_order']);
        $this->showFormModal = false;
        $this->resetValidation();
    }

    /**
     * Render the grades setup page.
     */
    public function render(): View
    {
        return view('livewire.setup.grades');
    }
}
