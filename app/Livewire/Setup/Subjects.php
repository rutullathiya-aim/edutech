<?php

namespace App\Livewire\Setup;

use App\Actions\Subjects\CreateStandardSubjects;
use App\Actions\Subjects\SyncGradeSubjects;
use App\Concerns\GradeSubjectValidationRules;
use App\Concerns\SubjectValidationRules;
use App\Enums\SubjectKind;
use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\Subject;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Subjects')]
class Subjects extends Component
{
    use GradeSubjectValidationRules, SubjectValidationRules;

    #[Locked]
    public ?string $editingId = null;

    public string $activeTab = 'subjects';

    public string $name = '';

    public ?string $code = '';

    public string $kind = SubjectKind::Scholastic->value;

    public bool $showFormModal = false;

    public string $selectedGradeId = '';

    /**
     * Grade curriculum input state keyed by subject ULID.
     *
     * @var array<string, array{enabled: bool, is_optional: bool, sort_order: int|string}>
     */
    public array $curriculumRows = [];

    /**
     * Authorize access and initialize default grade curriculum state.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Subject::class);

        $firstGrade = $this->activeGrades()->first();
        $this->selectedGradeId = $firstGrade instanceof Grade ? $firstGrade->id : '';

        $this->loadCurriculumRows();
    }

    /**
     * Switch between the Subjects and Grade curriculum tabs.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['subjects', 'curriculum'], true) ? $tab : 'subjects';
    }

    /**
     * Reload the checklist rows when switching the selected grade.
     */
    public function updatedSelectedGradeId(): void
    {
        $this->loadCurriculumRows();
    }

    /**
     * Get all subjects in the current school ordered by kind and name.
     *
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return Subject::query()
            ->ordered()
            ->get();
    }

    /**
     * Get all active (unarchived) subjects ordered by kind and name.
     *
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function activeSubjects(): Collection
    {
        return Subject::query()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Get all active grades ordered by sort_order for curriculum selection.
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
     * Get the currently selected active grade within the current school.
     */
    #[Computed]
    public function selectedGrade(): ?Grade
    {
        if ($this->selectedGradeId === '') {
            return null;
        }

        return $this->activeGrades()->firstWhere('id', $this->selectedGradeId);
    }

    /**
     * Get all subject kind enum options.
     *
     * @return list<SubjectKind>
     */
    #[Computed]
    public function subjectKinds(): array
    {
        return SubjectKind::cases();
    }

    /**
     * Determine whether the current user can create, update, archive, restore, or assign subjects.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Subject::class) ?? false;
    }

    /**
     * Open the modal to create a new subject.
     */
    public function openCreateModal(): void
    {
        $this->authorize('create', Subject::class);

        $this->resetForm();
        $this->showFormModal = true;
    }

    /**
     * Open the modal to edit an existing subject in the current school.
     */
    public function openEditModal(string $subjectId): void
    {
        $subject = Subject::findOrFail($subjectId);
        $this->authorize('update', $subject);

        $this->resetForm();
        $this->editingId = $subject->id;
        $this->name = $subject->name;
        $this->code = $subject->code ?? '';
        $this->kind = $subject->kind->value;
        $this->showFormModal = true;
    }

    /**
     * Validate and create or update a subject in the current school.
     */
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->code = filled($this->code) ? trim((string) $this->code) : null;
        $this->kind = trim($this->kind);

        if ($this->editingId !== null) {
            $subject = Subject::findOrFail($this->editingId);
            $this->authorize('update', $subject);

            /** @var array{name: string, code: string|null, kind: string} $validated */
            $validated = $this->validate($this->subjectRules($subject->id));

            $subject->update($validated);

            Flux::toast(variant: 'success', text: __('Subject updated.'));
        } else {
            $this->authorize('create', Subject::class);

            /** @var array{name: string, code: string|null, kind: string} $validated */
            $validated = $this->validate($this->subjectRules());

            Subject::create($validated);

            Flux::toast(variant: 'success', text: __('Subject created.'));
        }

        $this->resetForm();
        $this->loadCurriculumRows();
    }

    /**
     * Add missing standard subjects for the current school.
     */
    public function createStandardSubjects(CreateStandardSubjects $createStandardSubjects): void
    {
        $this->authorize('create', Subject::class);

        $created = $createStandardSubjects->handle();

        $this->loadCurriculumRows();

        if ($created->isEmpty()) {
            Flux::toast(text: __('All standard subjects are already set up.'));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            '{1} Added :count standard subject.|[2,*] Added :count standard subjects.',
            $created->count(),
            ['count' => $created->count()],
        ));
    }

    /**
     * Archive an active subject in the current school.
     */
    public function archive(string $subjectId): void
    {
        $subject = Subject::findOrFail($subjectId);
        $this->authorize('archive', $subject);

        if (! $subject->isArchived()) {
            $subject->archived_at = now();
            $subject->save();

            $this->loadCurriculumRows();

            Flux::toast(variant: 'success', text: __('Subject archived.'));
        }
    }

    /**
     * Restore a previously archived subject in the current school.
     */
    public function restore(string $subjectId): void
    {
        $subject = Subject::findOrFail($subjectId);
        $this->authorize('restore', $subject);

        if ($subject->isArchived()) {
            $subject->archived_at = null;
            $subject->save();

            $this->loadCurriculumRows();

            Flux::toast(variant: 'success', text: __('Subject restored.'));
        }
    }

    /**
     * Replace the selected grade's subject curriculum atomically.
     */
    public function saveCurriculum(SyncGradeSubjects $syncGradeSubjects): void
    {
        $this->authorize('manageCurriculum', Subject::class);

        $this->selectedGradeId = trim($this->selectedGradeId);

        $items = [];

        foreach ($this->curriculumRows as $subjectId => $row) {
            if (empty($row['enabled'])) {
                continue;
            }

            $rawOrder = $row['sort_order'];

            $items[] = [
                'subject_id' => (string) $subjectId,
                'is_optional' => (bool) $row['is_optional'],
                'sort_order' => is_string($rawOrder) ? trim($rawOrder) : $rawOrder,
            ];
        }

        /** @var array{grade_id: string, items: list<array{subject_id: string, is_optional: bool, sort_order: int|string}>} $validated */
        $validated = Validator::make(
            [
                'grade_id' => $this->selectedGradeId,
                'items' => $items,
            ],
            $this->gradeSubjectSyncRules(),
        )->validate();

        $grade = Grade::findOrFail($validated['grade_id']);

        $normalizedItems = array_map(
            static fn (array $item): array => [
                'subject_id' => $item['subject_id'],
                'is_optional' => (bool) $item['is_optional'],
                'sort_order' => (int) $item['sort_order'],
            ],
            $validated['items'],
        );

        $syncGradeSubjects->handle($grade, $normalizedItems);

        $this->resetValidation();
        $this->loadCurriculumRows();

        Flux::toast(variant: 'success', text: __('Grade curriculum saved.'));
    }

    /**
     * Cancel and reset the subject modal form state.
     */
    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Clear subject form inputs, editing target, and validation state.
     */
    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'code', 'kind']);
        $this->showFormModal = false;
        $this->resetValidation();
    }

    /**
     * Synchronize the checklist rows from active subjects and existing grade assignments.
     */
    public function loadCurriculumRows(): void
    {
        unset($this->activeSubjects, $this->subjects, $this->activeGrades, $this->selectedGrade);

        $grade = $this->selectedGrade();
        $activeSubjects = $this->activeSubjects();

        $existing = $grade instanceof Grade
            ? GradeSubject::query()
                ->where('grade_id', $grade->id)
                ->get()
                ->keyBy('subject_id')
            : new Collection;

        $rows = [];
        $orderCounter = 1;

        foreach ($activeSubjects as $subject) {
            /** @var GradeSubject|null $assigned */
            $assigned = $existing->get($subject->id);

            $rows[$subject->id] = [
                'enabled' => $assigned instanceof GradeSubject,
                'is_optional' => $assigned instanceof GradeSubject ? $assigned->is_optional : false,
                'sort_order' => $assigned instanceof GradeSubject ? $assigned->sort_order : $orderCounter,
            ];

            $orderCounter++;
        }

        $this->curriculumRows = $rows;
    }

    /**
     * Render the subjects and curriculum setup page.
     */
    public function render(): View
    {
        return view('livewire.setup.subjects');
    }
}
