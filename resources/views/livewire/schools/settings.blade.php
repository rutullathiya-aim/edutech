<div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 py-4">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('School settings') }}</flux:heading>
        <flux:subheading>
            {{ __('Configure weekly off days and calendar settings for :school.', ['school' => $this->school->name]) }}
        </flux:subheading>
    </div>

    <flux:card class="p-6">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-700">
            <div>
                <flux:heading size="lg">{{ __('Working week & timezone') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('School dates run in :timezone. Today in school time is :date (:day).', [
                        'timezone' => $this->school->timezone ?: 'Asia/Kolkata',
                        'date' => $this->school->today()->format('d/m/Y'),
                        'day' => $this->school->today()->format('l'),
                    ]) }}
                </flux:text>
            </div>
        </div>

        <form wire:submit="save" class="space-y-6">
            <flux:field>
                <flux:label>{{ __('Weekly off days') }}</flux:label>
                <flux:description>
                    {{ __('Select the days your school is closed each week. Sunday is selected by default; at least one working day must remain.') }}
                </flux:description>

                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($this->weekdayOptions as $dayNumber => $label)
                        <div class="rounded-lg border border-zinc-200 px-3 py-2.5 dark:border-zinc-700" wire:key="weekday-{{ $dayNumber }}">
                            <flux:checkbox
                                wire:model="weeklyOffMap.{{ $dayNumber }}"
                                :label="$label"
                                data-test="weekly-off-day-{{ $dayNumber }}"
                            />
                        </div>
                    @endforeach
                </div>

                <flux:error name="weekly_off_days" />
            </flux:field>

            <div class="flex items-center justify-end gap-3">
                <flux:button
                    type="submit"
                    variant="primary"
                    data-test="save-weekly-off-days"
                >
                    {{ __('Save settings') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
