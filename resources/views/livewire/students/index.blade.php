<div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Students') }}</flux:heading>
            <flux:subheading>{{ __('Browse enrolled students, search by admission number or guardian mobile, and open student profiles.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('import', \App\Models\Student::class)
                <flux:button
                    variant="outline"
                    icon="arrow-up-tray"
                    :href="route('students.import', ['school' => $this->school->id])"
                    wire:navigate
                    data-test="import-students-button"
                >
                    {{ __('Import students') }}
                </flux:button>
            @endcan

            @if ($this->canManage)
                <flux:button
                    variant="primary"
                    icon="plus"
                    :href="route('students.create', ['school' => $this->school->id])"
                    wire:navigate
                    data-test="admit-student-button"
                >
                    {{ __('Admit student') }}
                </flux:button>
            @endif
        </div>
    </div>

    <flux:card class="space-y-4 p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    :placeholder="__('Name, admission no, or guardian mobile...')"
                    clearable
                    data-test="students-search"
                />
            </div>

            <flux:select wire:model.live="academic_year_id" :label="__('Academic year')" data-test="students-year-filter">
                <flux:select.option value="">{{ __('All years') }}</flux:select.option>
                @foreach ($this->academicYears as $year)
                    <flux:select.option :value="$year->id" wire:key="student-filter-year-{{ $year->id }}">
                        {{ $year->name }}@if ($year->is_current) ({{ __('Current') }})@endif
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="grade_id" :label="__('Grade')" data-test="students-grade-filter">
                <flux:select.option value="">{{ __('All grades') }}</flux:select.option>
                @foreach ($this->grades as $grade)
                    <flux:select.option :value="$grade->id" wire:key="student-filter-grade-{{ $grade->id }}">
                        {{ $grade->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-2">
                <flux:select wire:model.live="section_id" :label="__('Section')" data-test="students-section-filter">
                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                    @foreach ($this->sections as $section)
                        <flux:select.option :value="$section->id" wire:key="student-filter-section-{{ $section->id }}">
                            {{ $this->grade_id === '' ? "{$section->grade->name} - {$section->name}" : $section->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="status" :label="__('Status')" data-test="students-status-filter">
                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                    @foreach (\App\Enums\StudentStatus::cases() as $studentStatus)
                        <flux:select.option :value="$studentStatus->value" wire:key="student-filter-status-{{ $studentStatus->value }}">
                            {{ $studentStatus->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </flux:card>

    <flux:card class="space-y-4 p-6">
        @if ($this->students->isEmpty())
            <div class="py-10 text-center">
                <flux:heading>{{ __('No students found') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Try clearing your filters or admit a new student for this school.') }}
                </flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Admission no') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Class – Section') }}</flux:table.column>
                    <flux:table.column>{{ __('Roll') }}</flux:table.column>
                    <flux:table.column>{{ __('Primary guardian') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->students as $student)
                        @php
                            $enrollment = $this->academic_year_id !== ''
                                ? $student->enrollments->firstWhere('academic_year_id', $this->academic_year_id)
                                : $student->enrollments->sortByDesc(static fn ($row): string => ($row->academicYear->is_current ? '1' : '0').$row->academicYear->starts_on->format('Y-m-d'))->first();
                            $primaryGuardian = $student->studentGuardians->first()?->guardian;
                        @endphp
                        <flux:table.row :key="$student->id" data-test="student-row-{{ $student->id }}">
                            <flux:table.cell class="font-mono text-xs">{{ $student->admission_no }}</flux:table.cell>
                            <flux:table.cell variant="strong">
                                <a
                                    href="{{ route('students.show', ['school' => $this->school->id, 'student' => $student->id]) }}"
                                    wire:navigate
                                    class="hover:underline"
                                >
                                    {{ $student->name }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $enrollment !== null ? "{$enrollment->section->grade->name} - {$enrollment->section->name}" : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $enrollment?->roll_number ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($primaryGuardian !== null)
                                    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $primaryGuardian->name }}</div>
                                    @if ($primaryGuardian->mobile !== null)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $primaryGuardian->mobile }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge
                                    :color="$student->status === \App\Enums\StudentStatus::Active ? 'green' : ($student->status === \App\Enums\StudentStatus::PassedOut ? 'sky' : 'zinc')"
                                    size="sm"
                                >
                                    {{ $student->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        :href="route('students.show', ['school' => $this->school->id, 'student' => $student->id])"
                                        wire:navigate
                                        data-test="view-student-{{ $student->id }}"
                                    >
                                        {{ __('View') }}
                                    </flux:button>

                                    @can('update', $student)
                                        <flux:button
                                            size="sm"
                                            variant="outline"
                                            :href="route('students.edit', ['school' => $this->school->id, 'student' => $student->id])"
                                            wire:navigate
                                            data-test="edit-student-{{ $student->id }}"
                                        >
                                            {{ __('Edit') }}
                                        </flux:button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            {{ $this->students->links() }}
        @endif
    </flux:card>
</div>
