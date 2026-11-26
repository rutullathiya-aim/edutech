<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Sections') }}</flux:heading>
            <flux:subheading>{{ __('Manage class divisions grouped by grade for each academic year.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            @if ($this->academicYears->isNotEmpty())
                <div class="min-w-48">
                    <flux:select
                        wire:model.live="academic_year_id"
                        :label="__('Academic year')"
                        data-test="section-year-selector"
                    >
                        @foreach ($this->academicYears as $year)
                            <flux:select.option :value="$year->id" wire:key="year-option-{{ $year->id }}">
                                {{ $year->name }}@if ($year->is_current) ({{ __('Current') }})@elseif ($year->isClosed()) ({{ __('Closed') }})@endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @if ($this->canManage && $this->selectedAcademicYear && ! $this->isSelectedYearClosed)
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="openCreateModal"
                    data-test="add-section-button"
                >
                    {{ __('Add section') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if ($this->isSelectedYearClosed)
        <flux:callout
            variant="warning"
            icon="lock-closed"
            :heading="__('Closed academic year')"
            :text="__('Sections in a closed academic year are read-only and cannot be added, edited, or deleted.')"
            data-test="closed-year-notice"
        />
    @endif

    @if ($this->academicYears->isEmpty())
        <flux:card class="p-6">
            <div class="py-10 text-center">
                <flux:heading>{{ __('No academic years configured') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Create an academic year before configuring class sections.') }}
                </flux:text>
            </div>
        </flux:card>
    @elseif ($this->gradeGroups->isEmpty())
        <flux:card class="p-6">
            <div class="py-10 text-center">
                <flux:heading>{{ __('No sections in :year yet', ['year' => $this->selectedAcademicYear?->name ?? __('this year')]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Add sections (for example, A and B) under your school grades for this academic year.') }}
                </flux:text>
            </div>
        </flux:card>
    @else
        <div class="space-y-4">
            @foreach ($this->gradeGroups as $grade)
                <flux:card class="p-6" wire:key="grade-group-{{ $grade->id }}">
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $grade->name }}</flux:heading>

                            @if ($grade->isArchived())
                                <flux:badge color="zinc" size="sm">{{ __('Archived') }}</flux:badge>
                            @endif
                        </div>

                        <flux:text class="text-xs">
                            {{ trans_choice('{1} :count section|[2,*] :count sections', $grade->sections->count(), ['count' => $grade->sections->count()]) }}
                        </flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Section') }}</flux:table.column>
                            <flux:table.column>{{ __('Room') }}</flux:table.column>
                            <flux:table.column>{{ __('Capacity') }}</flux:table.column>
                            @if ($this->canManage && ! $this->isSelectedYearClosed)
                                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                            @endif
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($grade->sections as $section)
                                <flux:table.row :key="$section->id">
                                    <flux:table.cell variant="strong">{{ $section->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $section->room ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $section->capacity ?? '—' }}</flux:table.cell>

                                    @if ($this->canManage && ! $this->isSelectedYearClosed)
                                        <flux:table.cell align="end">
                                            <div class="flex items-center justify-end gap-2">
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="openEditModal('{{ $section->id }}')"
                                                    data-test="edit-section-{{ $section->id }}"
                                                >
                                                    {{ __('Edit') }}
                                                </flux:button>

                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="confirmDelete('{{ $section->id }}')"
                                                    class="text-red-600 hover:text-red-700 dark:text-red-400"
                                                    data-test="delete-section-{{ $section->id }}"
                                                >
                                                    {{ __('Delete') }}
                                                </flux:button>
                                            </div>
                                        </flux:table.cell>
                                    @endif
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal
        name="section-form-modal"
        wire:model="showFormModal"
        @close="resetForm"
        class="max-w-lg"
    >
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId !== null ? __('Edit section') : __('Add section') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Configure the section under :year.', ['year' => $this->selectedAcademicYear?->name ?? '']) }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select
                    wire:model="grade_id"
                    :label="__('Grade')"
                    :placeholder="__('Select grade')"
                    required
                    data-test="section-grade-select"
                >
                    @foreach ($this->activeGrades as $gradeOption)
                        <flux:select.option :value="$gradeOption->id" wire:key="grade-option-{{ $gradeOption->id }}">
                            {{ $gradeOption->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="name"
                    :label="__('Section name')"
                    type="text"
                    placeholder="A"
                    required
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="capacity"
                        :label="__('Capacity (optional)')"
                        type="number"
                        min="1"
                        max="200"
                        placeholder="40"
                    />

                    <flux:input
                        wire:model="room"
                        :label="__('Room (optional)')"
                        type="text"
                        placeholder="Room 101"
                    />
                </div>

                @error('academic_year_id')
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
                @enderror
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" type="button" wire:click="cancel">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-section-button">
                    {{ $editingId !== null ? __('Update section') : __('Save section') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        name="delete-section-modal"
        wire:model="showDeleteModal"
        @close="resetDeleteModal"
        class="max-w-md"
    >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete section?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('This will remove the section from the selected academic year. This cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" type="button" wire:click="cancelDelete">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="danger" type="button" wire:click="delete" data-test="confirm-delete-section-button">
                    {{ __('Delete section') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
