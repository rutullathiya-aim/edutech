<div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Holidays') }}</flux:heading>
            <flux:subheading>
                {{ __('Plan public and school holidays for each academic year. Weekly off days are managed in Attendance settings.') }}
            </flux:subheading>
        </div>

        @if ($this->academicYears->isNotEmpty())
            <div class="min-w-48">
                <flux:select
                    wire:model.live="academic_year_id"
                    :label="__('Academic year')"
                    data-test="holiday-year-selector"
                >
                    @foreach ($this->academicYears as $year)
                        <flux:select.option :value="$year->id" wire:key="holiday-year-{{ $year->id }}">
                            {{ $year->name }}@if ($year->is_current) ({{ __('Current') }})@elseif ($year->isClosed()) ({{ __('Closed') }})@endif
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    @if ($this->isSelectedYearClosed)
        <flux:callout
            variant="warning"
            icon="lock-closed"
            :heading="__('Closed academic year')"
            :text="__('Holidays in a closed academic year are read-only and cannot be added or removed.')"
            data-test="closed-year-notice"
        />
    @endif

    @if ($errors->has('academic_year_id'))
        <flux:callout
            variant="danger"
            icon="exclamation-triangle"
            :heading="$errors->first('academic_year_id')"
            data-test="holiday-year-error"
        />
    @endif

    @if ($this->academicYears->isEmpty())
        <flux:card class="p-6">
            <div class="py-10 text-center">
                <flux:heading>{{ __('No academic years configured') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Create an academic year before planning school holidays.') }}
                </flux:text>
            </div>
        </flux:card>
    @else
        @if ($this->canManage && ! $this->isSelectedYearClosed)
            <flux:card class="p-6">
                <form wire:submit="addHoliday" class="grid grid-cols-1 gap-4 sm:grid-cols-12 sm:items-end">
                    <div class="sm:col-span-4">
                        <flux:field>
                            <flux:label>{{ __('Date') }}</flux:label>
                            <flux:input
                                type="date"
                                wire:model="date"
                                :min="$this->selectedAcademicYear?->starts_on->toDateString()"
                                :max="$this->selectedAcademicYear?->ends_on->toDateString()"
                                data-test="holiday-date-input"
                            />
                            <flux:error name="date" />
                        </flux:field>
                    </div>

                    <div class="sm:col-span-5">
                        <flux:field>
                            <flux:label>{{ __('Holiday name') }}</flux:label>
                            <flux:input
                                type="text"
                                wire:model="name"
                                maxlength="100"
                                :placeholder="__('For example, Independence Day')"
                                data-test="holiday-name-input"
                            />
                            <flux:error name="name" />
                        </flux:field>
                    </div>

                    <div class="sm:col-span-3">
                        <flux:button
                            type="submit"
                            variant="primary"
                            icon="plus"
                            class="w-full"
                            data-test="add-holiday-button"
                        >
                            {{ __('Add holiday') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @endif

        <flux:card class="p-6">
            @if ($this->holidays->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>{{ __('No holidays in :year yet', ['year' => $this->selectedAcademicYear?->name ?? __('this year')]) }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Add upcoming festivals and declared school closures above.') }}
                    </flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Day') }}</flux:table.column>
                        <flux:table.column>{{ __('Holiday') }}</flux:table.column>
                        @if ($this->canManage && ! $this->isSelectedYearClosed)
                            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->holidays as $holiday)
                            <flux:table.row :key="$holiday->id">
                                <flux:table.cell variant="strong">{{ $holiday->date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell>{{ $holiday->date->format('l') }}</flux:table.cell>
                                <flux:table.cell>{{ $holiday->name }}</flux:table.cell>

                                @if ($this->canManage && ! $this->isSelectedYearClosed)
                                    <flux:table.cell align="end">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="removeHoliday('{{ $holiday->id }}')"
                                            wire:confirm="{{ __('Remove :name on :date?', ['name' => $holiday->name, 'date' => $holiday->date->format('d/m/Y')]) }}"
                                            data-test="remove-holiday-{{ $holiday->id }}"
                                        >
                                            {{ __('Remove') }}
                                        </flux:button>
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endif
</div>
