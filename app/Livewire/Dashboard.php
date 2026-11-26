<?php

namespace App\Livewire;

use App\Enums\SchoolRole;
use App\Enums\SchoolStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read School $school
 * @property-read AcademicYear|null $currentAcademicYear
 * @property-read int|null $trialDaysRemaining
 * @property-read bool $canSeeTrialBanner
 * @property-read bool $canViewSetupCounts
 * @property-read bool $canManageAnySetup
 * @property-read array{active_grades: int, current_year_sections: int, active_subjects: int} $setupStats
 * @property-read bool $everyActiveGradeHasSubject
 * @property-read list<array{key: string, label: string, description: string, completed: bool, route: string, ability_model: class-string}> $checklistSteps
 * @property-read int $completedChecklistCount
 * @property-read bool $showChecklist
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    /**
     * Get the currently scoped school.
     */
    #[Computed]
    public function school(): School
    {
        return CurrentSchool::getOrFail();
    }

    /**
     * Get the current school's active academic year, if one is marked current.
     */
    #[Computed]
    public function currentAcademicYear(): ?AcademicYear
    {
        return AcademicYear::query()
            ->current()
            ->first();
    }

    /**
     * Calculate whole remaining trial days rounded up, or null if not in an active trial.
     */
    #[Computed]
    public function trialDaysRemaining(): ?int
    {
        $school = $this->school;

        if ($school->status !== SchoolStatus::Trial || $school->trial_ends_at === null) {
            return null;
        }

        $secondsRemaining = now()->diffInSeconds($school->trial_ends_at, false);

        if ($secondsRemaining < 0) {
            return null;
        }

        return (int) ceil($secondsRemaining / 86400);
    }

    /**
     * Determine whether the current user should see the trial banner for this school.
     */
    #[Computed]
    public function canSeeTrialBanner(): bool
    {
        if ($this->trialDaysRemaining === null) {
            return false;
        }

        $school = $this->school;
        $user = auth()->user();

        if (! $user instanceof User || getPermissionsTeamId() !== $school->id) {
            return false;
        }

        return $user->hasAnyRole([
            SchoolRole::Owner->value,
            SchoolRole::Admin->value,
        ]);
    }

    /**
     * Determine whether the current user can view any school setup counts.
     */
    #[Computed]
    public function canViewSetupCounts(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('viewAny', Grade::class)
            || $user->can('viewAny', Section::class)
            || $user->can('viewAny', Subject::class);
    }

    /**
     * Determine whether the current user can manage at least one school setup module.
     */
    #[Computed]
    public function canManageAnySetup(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('create', AcademicYear::class)
            || $user->can('create', Grade::class)
            || $user->can('create', Section::class)
            || $user->can('create', Subject::class);
    }

    /**
     * Load the school's setup summary counts in O(1) scoped queries.
     *
     * @return array{active_grades: int, current_year_sections: int, active_subjects: int}
     */
    #[Computed]
    public function setupStats(): array
    {
        if (! $this->canViewSetupCounts && ! $this->canManageAnySetup) {
            return [
                'active_grades' => 0,
                'current_year_sections' => 0,
                'active_subjects' => 0,
            ];
        }

        $currentAcademicYear = $this->currentAcademicYear;

        return [
            'active_grades' => Grade::query()->active()->count(),
            'current_year_sections' => $currentAcademicYear !== null
                ? Section::query()->where('academic_year_id', $currentAcademicYear->id)->count()
                : 0,
            'active_subjects' => Subject::query()->active()->count(),
        ];
    }

    /**
     * Determine whether at least one active grade exists and every active grade has subjects.
     */
    #[Computed]
    public function everyActiveGradeHasSubject(): bool
    {
        if (! $this->canManageAnySetup) {
            return false;
        }

        $stats = $this->setupStats;

        if ($stats['active_grades'] === 0) {
            return false;
        }

        return Grade::query()
            ->active()
            ->whereDoesntHave('gradeSubjects')
            ->doesntExist();
    }

    /**
     * Build the ordered school setup checklist steps for school managers.
     *
     * @return list<array{key: string, label: string, description: string, completed: bool, route: string, ability_model: class-string}>
     */
    #[Computed]
    public function checklistSteps(): array
    {
        if (! $this->canManageAnySetup) {
            return [];
        }

        $school = $this->school;
        $stats = $this->setupStats;

        return [
            [
                'key' => 'academic-year',
                'label' => __('Set your current academic year'),
                'description' => __('Create an academic year and mark it as current.'),
                'completed' => $this->currentAcademicYear !== null,
                'route' => route('setup.academic-years', ['school' => $school->id]),
                'ability_model' => AcademicYear::class,
            ],
            [
                'key' => 'grades',
                'label' => __('Add grades'),
                'description' => __('Create at least one active grade or class.'),
                'completed' => $stats['active_grades'] > 0,
                'route' => route('setup.grades', ['school' => $school->id]),
                'ability_model' => Grade::class,
            ],
            [
                'key' => 'sections',
                'label' => __('Add sections for the current year'),
                'description' => __('Create at least one section under the current academic year.'),
                'completed' => $stats['current_year_sections'] > 0,
                'route' => route('setup.sections', ['school' => $school->id]),
                'ability_model' => Section::class,
            ],
            [
                'key' => 'subjects',
                'label' => __('Add subjects'),
                'description' => __('Create at least one active scholastic or co-scholastic subject.'),
                'completed' => $stats['active_subjects'] > 0,
                'route' => route('setup.subjects', ['school' => $school->id]),
                'ability_model' => Subject::class,
            ],
            [
                'key' => 'curriculum',
                'label' => __('Assign subjects to every grade'),
                'description' => __('Make sure every active grade has at least one assigned subject.'),
                'completed' => $this->everyActiveGradeHasSubject,
                'route' => route('setup.subjects', ['school' => $school->id]),
                'ability_model' => Subject::class,
            ],
        ];
    }

    /**
     * Count how many setup checklist steps have been completed.
     */
    #[Computed]
    public function completedChecklistCount(): int
    {
        $completed = 0;

        foreach ($this->checklistSteps as $step) {
            if ($step['completed']) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * Determine whether the setup checklist card should be displayed.
     */
    #[Computed]
    public function showChecklist(): bool
    {
        return $this->canManageAnySetup
            && $this->completedChecklistCount < count($this->checklistSteps);
    }

    /**
     * Render the school dashboard view.
     */
    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
