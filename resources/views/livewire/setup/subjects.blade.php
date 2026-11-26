<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Subjects') }}</flux:heading>
            <flux:subheading>{{ __('Manage scholastic and co-scholastic subjects and assign each grade’s curriculum.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                :variant="$activeTab === 'subjects' ? 'primary' : 'outline'"
                wire:click="setTab('subjects')"
                data-test="tab-subjects"
            >
                {{ __('Subjects') }}
            </flux:button>

            <flux:button
                :variant="$activeTab === 'curriculum' ? 'primary' : 'outline'"
                wire:click="setTab('curriculum')"
                data-test="tab-curriculum"
            >
                {{ __('Grade curriculum') }}
            </flux:button>
        </div>
    </div>

    @if ($activeTab === 'subjects')
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div></div>

            @if ($this->canManage)
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button
                        variant="outline"
                        wire:click="createStandardSubjects"
                        data-test="add-standard-subjects-button"
                    >
                        {{ __('Add standard subjects') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        icon="plus"
                        wire:click="openCreateModal"
                        data-test="add-subject-button"
                    >
                        {{ __('Add subject') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <flux:card class="p-6">
            @if ($this->subjects->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>{{ __('No subjects configured yet') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Add standard subjects or create custom scholastic and co-scholastic subjects for your school.') }}
                    </flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Subject') }}</flux:table.column>
                        <flux:table.column>{{ __('Code') }}</flux:table.column>
                        <flux:table.column>{{ __('Category') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        @if ($this->canManage)
                            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->subjects as $subject)
                            <flux:table.row :key="$subject->id">
                                <flux:table.cell variant="strong">{{ $subject->name }}</flux:table.cell>
                                <flux:table.cell>{{ $subject->code ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge
                                        :color="$subject->kind === \App\Enums\SubjectKind::Scholastic ? 'sky' : 'purple'"
                                        size="sm"
                                    >
                                        {{ $subject->kind->label() }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($subject->isArchived())
                                        <flux:badge color="zinc" size="sm">{{ __('Archived') }}</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                    @endif
                                </flux:table.cell>

                                @if ($this->canManage)
                                    <flux:table.cell align="end">
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                wire:click="openEditModal('{{ $subject->id }}')"
                                                data-test="edit-subject-{{ $subject->id }}"
                                            >
                                                {{ __('Edit') }}
                                            </flux:button>

                                            @if ($subject->isArchived())
                                                <flux:button
                                                    size="sm"
                                                    variant="outline"
                                                    wire:click="restore('{{ $subject->id }}')"
                                                    data-test="restore-subject-{{ $subject->id }}"
                                                >
                                                    {{ __('Restore') }}
                                                </flux:button>
                                            @else
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="archive('{{ $subject->id }}')"
                                                    data-test="archive-subject-{{ $subject->id }}"
                                                >
                                                    {{ __('Archive') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @else
        <flux:card class="space-y-6 p-6">
            @if ($this->activeGrades->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>{{ __('No active grades available') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Create or restore at least one grade before assigning curriculum subjects.') }}
                    </flux:text>
                </div>
            @elseif ($this->activeSubjects->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>{{ __('No active subjects available') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Add active subjects in the Subjects tab before configuring a grade curriculum.') }}
                    </flux:text>
                </div>
            @else
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div class="w-full max-w-xs">
                        <flux:select
                            wire:model.live="selectedGradeId"
                            :label="__('Grade')"
                            data-test="curriculum-grade-select"
                        >
                            @foreach ($this->activeGrades as $gradeOption)
                                <flux:select.option :value="$gradeOption->id" wire:key="curriculum-grade-{{ $gradeOption->id }}">
                                    {{ $gradeOption->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    @if ($this->canManage)
                        <flux:button
                            variant="primary"
                            wire:click="saveCurriculum"
                            data-test="save-curriculum-button"
                        >
                            {{ __('Save curriculum') }}
                        </flux:button>
                    @endif
                </div>

                @if ($errors->any())
                    <flux:callout
                        variant="danger"
                        icon="exclamation-triangle"
                        :heading="$errors->first()"
                    />
                @endif

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Include') }}</flux:table.column>
                        <flux:table.column>{{ __('Subject') }}</flux:table.column>
                        <flux:table.column>{{ __('Category') }}</flux:table.column>
                        <flux:table.column>{{ __('Optional') }}</flux:table.column>
                        <flux:table.column>{{ __('Report order') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->activeSubjects as $subject)
                            <flux:table.row :key="$subject->id">
                                <flux:table.cell>
                                    <flux:checkbox
                                        wire:model="curriculumRows.{{ $subject->id }}.enabled"
                                        :disabled="! $this->canManage"
                                        :aria-label="__('Include :subject', ['subject' => $subject->name])"
                                        data-test="curriculum-enable-{{ $subject->id }}"
                                    />
                                </flux:table.cell>

                                <flux:table.cell variant="strong">
                                    <div>{{ $subject->name }}</div>
                                    @if ($subject->code !== null)
                                        <div class="text-xs font-normal text-zinc-500">{{ $subject->code }}</div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge
                                        :color="$subject->kind === \App\Enums\SubjectKind::Scholastic ? 'sky' : 'purple'"
                                        size="sm"
                                    >
                                        {{ $subject->kind->label() }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:switch
                                        wire:model="curriculumRows.{{ $subject->id }}.is_optional"
                                        :disabled="! $this->canManage"
                                        :aria-label="__('Optional :subject', ['subject' => $subject->name])"
                                        data-test="curriculum-optional-{{ $subject->id }}"
                                    />
                                </flux:table.cell>

                                <flux:table.cell class="w-32">
                                    <flux:input
                                        wire:model="curriculumRows.{{ $subject->id }}.sort_order"
                                        type="number"
                                        min="0"
                                        max="200"
                                        :disabled="! $this->canManage"
                                        :aria-label="__('Order for :subject', ['subject' => $subject->name])"
                                        data-test="curriculum-order-{{ $subject->id }}"
                                    />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endif

    <flux:modal
        name="subject-form-modal"
        wire:model="showFormModal"
        @close="resetForm"
        class="max-w-lg"
    >
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId !== null ? __('Edit subject') : __('Add subject') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Archive subjects rather than deleting to preserve grade curriculum and report cards.') }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input
                    wire:model="name"
                    :label="__('Subject name')"
                    type="text"
                    placeholder="Mathematics"
                    required
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="code"
                        :label="__('Subject code (optional)')"
                        type="text"
                        placeholder="041"
                    />

                    <flux:select
                        wire:model="kind"
                        :label="__('Category')"
                        required
                    >
                        @foreach ($this->subjectKinds as $subjectKind)
                            <flux:select.option :value="$subjectKind->value" wire:key="subject-kind-{{ $subjectKind->value }}">
                                {{ $subjectKind->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" type="button" wire:click="cancel">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-subject-button">
                    {{ $editingId !== null ? __('Update subject') : __('Save subject') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
