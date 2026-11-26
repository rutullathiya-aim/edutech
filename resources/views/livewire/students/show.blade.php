<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    @php
        $student = $this->student;
        $orderedEnrollments = $student->enrollments
            ->sortByDesc(static fn ($row): string => ($row->academicYear->is_current ? '1' : '0').$row->academicYear->starts_on->format('Y-m-d'));
        $currentEnrollment = $orderedEnrollments->first();
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="space-y-1.5">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ $student->name }}</flux:heading>
                <flux:badge
                    :color="$student->status === \App\Enums\StudentStatus::Active ? 'green' : ($student->status === \App\Enums\StudentStatus::PassedOut ? 'sky' : 'zinc')"
                    size="sm"
                >
                    {{ $student->status->label() }}
                </flux:badge>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <span class="font-mono">{{ $student->admission_no }}</span>

                @if ($currentEnrollment !== null)
                    <span>· {{ $currentEnrollment->section->grade->name }} - {{ $currentEnrollment->section->name }}</span>
                    @if ($currentEnrollment->roll_number !== null)
                        <span>· {{ __('Roll :roll', ['roll' => $currentEnrollment->roll_number]) }}</span>
                    @endif
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="ghost"
                :href="route('students.index', ['school' => $this->school->id])"
                wire:navigate
            >
                {{ __('Back to students') }}
            </flux:button>

            @if ($this->canManage)
                <flux:button
                    variant="primary"
                    icon="pencil-square"
                    :href="route('students.edit', ['school' => $this->school->id, 'student' => $student->id])"
                    wire:navigate
                    data-test="edit-student-profile-button"
                >
                    {{ __('Edit student') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <flux:button
            :variant="$activeTab === 'profile' ? 'primary' : 'outline'"
            wire:click="setTab('profile')"
            data-test="tab-profile"
        >
            {{ __('Profile') }}
        </flux:button>

        <flux:button
            :variant="$activeTab === 'guardians' ? 'primary' : 'outline'"
            wire:click="setTab('guardians')"
            data-test="tab-guardians"
        >
            {{ __('Guardians') }}
        </flux:button>

        <flux:button
            :variant="$activeTab === 'history' ? 'primary' : 'outline'"
            wire:click="setTab('history')"
            data-test="tab-history"
        >
            {{ __('Enrollment history') }}
        </flux:button>
    </div>

    @if ($activeTab === 'profile')
        <div class="grid gap-6 md:grid-cols-2">
            <flux:card class="space-y-4 p-6">
                <flux:heading size="lg">{{ __('Basic details') }}</flux:heading>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Admission number') }}</dt>
                    <dd class="font-medium">{{ $student->admission_no }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Admission date') }}</dt>
                    <dd class="font-medium">{{ \App\Support\IndianDate::format($student->admission_date) }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Date of birth') }}</dt>
                    <dd class="font-medium">{{ \App\Support\IndianDate::format($student->date_of_birth) }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Gender') }}</dt>
                    <dd class="font-medium">{{ $student->gender->label() }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Nationality') }}</dt>
                    <dd class="font-medium">{{ $student->nationality }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Mother tongue') }}</dt>
                    <dd class="font-medium">{{ $student->mother_tongue ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Blood group') }}</dt>
                    <dd class="font-medium">{{ $student->blood_group?->label() ?? '—' }}</dd>
                </dl>
            </flux:card>

            <flux:card class="space-y-4 p-6">
                <flux:heading size="lg">{{ __('Address and schooling') }}</flux:heading>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                    <dd class="font-medium">{{ $student->address_line ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('City / Village') }}</dt>
                    <dd class="font-medium">{{ $student->city ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('State / UT') }}</dt>
                    <dd class="font-medium">{{ $student->state?->value ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('PIN code') }}</dt>
                    <dd class="font-medium">{{ $student->pincode ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Previous school') }}</dt>
                    <dd class="font-medium">{{ $student->previous_school ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Previous TC number') }}</dt>
                    <dd class="font-medium">{{ $student->previous_tc_no ?? '—' }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('DPDP consent') }}</dt>
                    <dd class="font-medium">
                        @if ($student->consent_recorded_at !== null)
                            {{ $student->consent_recorded_at->timezone($this->school->timezone ?: 'Asia/Kolkata')->format('d/m/Y H:i') }}
                            @if ($student->consentRecordedBy !== null)
                                ({{ $student->consentRecordedBy->name }})
                            @endif
                        @else
                            {{ __('Not recorded') }}
                        @endif
                    </dd>
                </dl>
            </flux:card>

            @if ($this->canViewSensitive)
                <flux:card class="space-y-4 p-6 md:col-span-2" data-test="sensitive-student-details">
                    <flux:heading size="lg">{{ __('Statutory and sensitive details') }}</flux:heading>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Social category') }}</dt>
                            <dd class="font-medium">{{ $student->social_category?->label() ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Religion') }}</dt>
                            <dd class="font-medium">{{ $student->religion ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('APAAR ID') }}</dt>
                            <dd class="font-medium font-mono">{{ $student->apaar_id ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('UDISE+ PEN') }}</dt>
                            <dd class="font-medium font-mono">{{ $student->pen ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Aadhaar (last 4 digits)') }}</dt>
                            <dd class="font-medium font-mono">{{ $student->aadhaar_last4 ?? '—' }}</dd>
                        </div>
                    </dl>
                </flux:card>
            @endif
        </div>
    @elseif ($activeTab === 'guardians')
        <div class="space-y-4" data-test="guardians-tab-content">
            @forelse ($student->studentGuardians as $link)
                @php
                    $guardian = $link->guardian;
                    $siblings = $guardian->studentGuardians->filter(static fn ($otherLink): bool => $otherLink->student_id !== $student->id);
                @endphp
                <flux:card class="space-y-3 p-6" wire:key="profile-guardian-{{ $link->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $guardian->name }}</flux:heading>
                            <flux:badge color="zinc" size="sm">{{ $link->relationship->label() }}</flux:badge>
                            @if ($link->isPrimary())
                                <flux:badge color="green" size="sm">{{ __('Primary contact') }}</flux:badge>
                            @endif
                        </div>

                        @if ($guardian->mobile !== null)
                            <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $guardian->mobile }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-4 text-sm text-zinc-600 dark:text-zinc-400">
                        <span>{{ __('Email') }}: {{ $guardian->email ?? '—' }}</span>
                        <span>{{ __('Occupation') }}: {{ $guardian->occupation ?? '—' }}</span>
                    </div>

                    @if ($siblings->isNotEmpty())
                        <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700" data-test="guardian-siblings-{{ $guardian->id }}">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ __('Siblings sharing this guardian') }}
                            </div>
                            <ul class="space-y-1 text-sm">
                                @foreach ($siblings as $siblingLink)
                                    @php
                                        $sibling = $siblingLink->student;
                                        $siblingEnrollment = $sibling->enrollments
                                            ->sortByDesc(static fn ($row): string => ($row->academicYear->is_current ? '1' : '0').$row->academicYear->starts_on->format('Y-m-d'))
                                            ->first();
                                    @endphp
                                    <li>
                                        <a
                                            href="{{ route('students.show', ['school' => $this->school->id, 'student' => $sibling->id]) }}"
                                            wire:navigate
                                            class="font-medium text-sky-700 hover:underline dark:text-sky-400"
                                        >
                                            {{ $sibling->name }}
                                        </a>
                                        <span class="text-zinc-500 dark:text-zinc-400">
                                            ({{ $sibling->admission_no }}@if ($siblingEnrollment !== null) · {{ $siblingEnrollment->section->grade->name }} - {{ $siblingEnrollment->section->name }}@endif)
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </flux:card>
            @empty
                <flux:card class="p-6">
                    <flux:text>{{ __('No guardians linked to this student.') }}</flux:text>
                </flux:card>
            @endforelse
        </div>
    @else
        <flux:card class="p-6" data-test="history-tab-content">
            @if ($orderedEnrollments->isEmpty())
                <flux:text>{{ __('No enrollment history recorded yet.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Academic year') }}</flux:table.column>
                        <flux:table.column>{{ __('Class – Section') }}</flux:table.column>
                        <flux:table.column>{{ __('Roll') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Enrolled on') }}</flux:table.column>
                        <flux:table.column>{{ __('Left on') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($orderedEnrollments as $enrollment)
                            <flux:table.row :key="$enrollment->id">
                                <flux:table.cell variant="strong">
                                    {{ $enrollment->academicYear->name }}
                                    @if ($enrollment->academicYear->is_current)
                                        <flux:badge color="green" size="sm">{{ __('Current') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $enrollment->section->grade->name }} - {{ $enrollment->section->name }}</flux:table.cell>
                                <flux:table.cell>{{ $enrollment->roll_number ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="zinc">{{ $enrollment->status->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ \App\Support\IndianDate::format($enrollment->enrolled_on) }}</flux:table.cell>
                                <flux:table.cell>{{ $enrollment->left_on !== null ? \App\Support\IndianDate::format($enrollment->left_on) : '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endif
</div>
