<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Academic years') }}</flux:heading>
            <flux:subheading>{{ __('Manage academic sessions and choose the active school year.') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <flux:button
                variant="primary"
                icon="plus"
                wire:click="openCreateModal"
                data-test="add-academic-year-button"
            >
                {{ __('Add academic year') }}
            </flux:button>
        @endif
    </div>

    <flux:card class="p-6">
        @if ($this->academicYears->isEmpty())
            <div class="py-10 text-center">
                <flux:heading>{{ __('No academic years yet') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Add your first academic year (for example, 2026-27) to begin setting up sections and enrollments.') }}
                </flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Starts on') }}</flux:table.column>
                    <flux:table.column>{{ __('Ends on') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    @if ($this->canManage)
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    @endif
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->academicYears as $academicYear)
                        <flux:table.row :key="$academicYear->id">
                            <flux:table.cell variant="strong">{{ $academicYear->name }}</flux:table.cell>
                            <flux:table.cell>{{ $academicYear->starts_on->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $academicYear->ends_on->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    @if ($academicYear->is_current)
                                        <flux:badge color="green" size="sm">{{ __('Current') }}</flux:badge>
                                    @endif

                                    @if ($academicYear->isClosed())
                                        <flux:badge color="zinc" size="sm">{{ __('Closed') }}</flux:badge>
                                    @elseif (! $academicYear->is_current)
                                        <flux:badge color="sky" size="sm">{{ __('Open') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            @if ($this->canManage)
                                <flux:table.cell align="end">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($academicYear->isClosed())
                                            <flux:text class="text-xs">{{ __('Read-only') }}</flux:text>
                                        @else
                                            @if (! $academicYear->is_current)
                                                <flux:button
                                                    size="sm"
                                                    variant="outline"
                                                    wire:click="makeCurrent('{{ $academicYear->id }}')"
                                                    data-test="make-current-{{ $academicYear->id }}"
                                                >
                                                    {{ __('Make current') }}
                                                </flux:button>
                                            @endif

                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                wire:click="openEditModal('{{ $academicYear->id }}')"
                                                data-test="edit-academic-year-{{ $academicYear->id }}"
                                            >
                                                {{ __('Edit') }}
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
        name="academic-year-form-modal"
        wire:model="showFormModal"
        @close="resetForm"
        class="max-w-lg"
    >
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingId !== null ? __('Edit academic year') : __('Add academic year') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Use the YYYY-YY format (for example, 2027-28). Dates cannot overlap an existing year.') }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input
                    wire:model="name"
                    :label="__('Academic year name')"
                    type="text"
                    placeholder="2027-28"
                    required
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="starts_on"
                        :label="__('Start date')"
                        type="date"
                        required
                    />

                    <flux:input
                        wire:model="ends_on"
                        :label="__('End date')"
                        type="date"
                        required
                    />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" type="button" wire:click="cancel">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-academic-year-button">
                    {{ $editingId !== null ? __('Update academic year') : __('Save academic year') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
