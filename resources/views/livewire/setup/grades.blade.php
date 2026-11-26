<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Grades') }}</flux:heading>
            <flux:subheading>{{ __('Manage classes from Nursery to Class 12, display order, and archived grades.') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    variant="outline"
                    wire:click="createStandardGrades"
                    data-test="add-standard-grades-button"
                >
                    {{ __('Add standard grades') }}
                </flux:button>

                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="openCreateModal"
                    data-test="add-grade-button"
                >
                    {{ __('Add grade') }}
                </flux:button>
            </div>
        @endif
    </div>

    <flux:card class="p-6">
        @if ($this->grades->isEmpty())
            <div class="py-10 text-center">
                <flux:heading>{{ __('No grades configured yet') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Add standard Nursery through Class 12 grades or create custom grades for your school.') }}
                </flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Order') }}</flux:table.column>
                    <flux:table.column>{{ __('Grade') }}</flux:table.column>
                    <flux:table.column>{{ __('Short name') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    @if ($this->canManage)
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    @endif
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->grades as $grade)
                        <flux:table.row :key="$grade->id">
                            <flux:table.cell>{{ $grade->sort_order }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $grade->name }}</flux:table.cell>
                            <flux:table.cell>{{ $grade->short_name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($grade->isArchived())
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
                                            wire:click="openEditModal('{{ $grade->id }}')"
                                            data-test="edit-grade-{{ $grade->id }}"
                                        >
                                            {{ __('Edit') }}
                                        </flux:button>

                                        @if ($grade->isArchived())
                                            <flux:button
                                                size="sm"
                                                variant="outline"
                                                wire:click="restore('{{ $grade->id }}')"
                                                data-test="restore-grade-{{ $grade->id }}"
                                            >
                                                {{ __('Restore') }}
                                            </flux:button>
                                        @else
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                wire:click="archive('{{ $grade->id }}')"
                                                data-test="archive-grade-{{ $grade->id }}"
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

    <flux:modal
        name="grade-form-modal"
        wire:model="showFormModal"
        @close="resetForm"
        class="max-w-lg"
    >
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId !== null ? __('Edit grade') : __('Add grade') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Grades persist across academic years and follow sort order for promotion.') }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input
                    wire:model="name"
                    :label="__('Grade name')"
                    type="text"
                    placeholder="Class 5"
                    required
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="short_name"
                        :label="__('Short name (optional)')"
                        type="text"
                        placeholder="5"
                    />

                    <flux:input
                        wire:model="sort_order"
                        :label="__('Sort order')"
                        type="number"
                        min="0"
                        max="50"
                        placeholder="8"
                        required
                    />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" type="button" wire:click="cancel">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-grade-button">
                    {{ $editingId !== null ? __('Update grade') : __('Save grade') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
