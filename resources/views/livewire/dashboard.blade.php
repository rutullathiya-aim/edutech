<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between" data-test="school-header">
        <div class="space-y-1.5">
            <flux:heading size="xl">{{ $this->school->name }}</flux:heading>

            <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                @if (filled($this->school->location))
                <span>{{ $this->school->location }}</span>
                @endif

                @if ($this->school->board !== null)
                <flux:badge color="zinc" size="sm" data-test="school-board-badge">
                    {{ $this->school->board->value }}
                </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->currentAcademicYear !== null)
            <flux:badge color="green" size="sm" data-test="current-year-badge">
                {{ __('Current year: :year', ['year' => $this->currentAcademicYear->name]) }}
            </flux:badge>
            @else
            <flux:badge color="amber" size="sm" data-test="no-current-year-warning">
                {{ __('No current academic year') }}
            </flux:badge>
            @endif
        </div>
    </div>

    @if ($this->canSeeTrialBanner && $this->trialDaysRemaining !== null)
    <flux:callout variant="warning" icon="clock" data-test="trial-banner">
        <flux:callout.heading>
            @if ($this->trialDaysRemaining === 0)
            {{ __('Your trial ends today') }}
            @else
            {{ trans_choice('Your trial ends in :count day|Your trial ends in :count days', $this->trialDaysRemaining, ['count' => $this->trialDaysRemaining]) }}
            @endif
        </flux:callout.heading>
    </flux:callout>
    @endif

    @if ($this->showChecklist)
    <flux:card class="space-y-4 p-6" data-test="setup-checklist">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">{{ __('Finish setting up your school') }}</flux:heading>
                <flux:subheading>{{ __('Complete these steps so classes and subjects are ready for admissions and timetables.') }}</flux:subheading>
            </div>

            <flux:badge color="zinc" size="sm" data-test="checklist-progress">
                {{ __(':completed of :total complete', ['completed' => $this->completedChecklistCount, 'total' => count($this->checklistSteps)]) }}
            </flux:badge>
        </div>

        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->checklistSteps as $step)
            <li
                wire:key="checklist-step-{{ $step['key'] }}"
                data-test="checklist-step-{{ $step['key'] }}"
                class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                <div class="flex items-start gap-3">
                    <span aria-hidden="true" class="mt-0.5 text-base leading-none">
                        {{ $step['completed'] ? '✅' : '⬜' }}
                    </span>

                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $step['label'] }}
                        </div>
                        <div class="text-xs text-zinc-600 dark:text-zinc-400">
                            {{ $step['description'] }}
                        </div>
                    </div>
                </div>

                @can('viewAny', $step['ability_model'])
                <flux:button
                    size="sm"
                    :variant="$step['completed'] ? 'ghost' : 'outline'"
                    :href="$step['route']"
                    wire:navigate
                    data-test="checklist-link-{{ $step['key'] }}">
                    {{ $step['completed'] ? __('Manage') : __('Open') }}
                </flux:button>
                @endcan
            </li>
            @endforeach
        </ul>
    </flux:card>
    @endif

    @if ($this->canViewSetupCounts)
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="setup-stats">
        @can('viewAny', \App\Models\Grade::class)
        <flux:card class="space-y-1 p-5" data-test="stat-grades">
            <flux:text class="text-sm">{{ __('Active grades') }}</flux:text>
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ $this->setupStats['active_grades'] }}
            </div>
        </flux:card>
        @endcan

        @can('viewAny', \App\Models\Section::class)
        <flux:card class="space-y-1 p-5" data-test="stat-sections">
            <flux:text class="text-sm">{{ __('Current-year sections') }}</flux:text>
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ $this->setupStats['current_year_sections'] }}
            </div>
        </flux:card>
        @endcan

        @can('viewAny', \App\Models\Subject::class)
        <flux:card class="space-y-1 p-5" data-test="stat-subjects">
            <flux:text class="text-sm">{{ __('Active subjects') }}</flux:text>
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ $this->setupStats['active_subjects'] }}
            </div>
        </flux:card>
        @endcan

        @can('viewAny', \App\Models\Student::class)
        <flux:card class="space-y-1 p-5" data-test="stat-students">
            <flux:text class="text-sm">{{ __('Active students') }}</flux:text>
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ $this->setupStats['current_year_students'] }}
            </div>
        </flux:card>
        @endcan
    </div>
    @endif

    @if (! $this->canViewSetupCounts && ! $this->canManageAnySetup)
    <flux:card class="space-y-2 p-6" data-test="welcome-card">
        <flux:heading size="lg">
            {{ __('Welcome to :school', ['school' => $this->school->name]) }}
        </flux:heading>
        <flux:text>
            {{ __('You are signed in to your school portal. Additional modules for classes, attendance, fees, and results will appear here as they are enabled.') }}
        </flux:text>
    </flux:card>
    @endif
</div>