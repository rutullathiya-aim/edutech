<div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Admit student') }}</flux:heading>
            <flux:subheading>{{ __('Enter birth-certificate details, class placement, guardians, address, IDs, and DPDP consent.') }}</flux:subheading>
        </div>

        <flux:button
            variant="ghost"
            :href="route('students.index', ['school' => $this->school->id])"
            wire:navigate
        >
            {{ __('Back to students') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="form-error-summary">
                <flux:callout.heading>{{ __('Please fix the following errors before saving.') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="mt-1 list-disc space-y-0.5 ps-5 text-sm">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        {{-- Group 1: Student --}}
        <flux:card class="space-y-4 p-6">
            <flux:heading size="lg">{{ __('1. Student details') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="admission_no"
                    :label="__('Admission number')"
                    required
                    :placeholder="__('e.g. SPS-0143')"
                    data-test="admit-admission-no"
                />

                <flux:input
                    wire:model="admission_date"
                    :label="__('Admission date (dd/mm/yyyy)')"
                    required
                    :placeholder="__('dd/mm/yyyy')"
                    data-test="admit-admission-date"
                />

                <div class="sm:col-span-2">
                    <flux:input
                        wire:model="name"
                        :label="__('Full name (as per birth certificate)')"
                        required
                        :placeholder="__('e.g. Aarav Rajesh Sharma')"
                        data-test="admit-name"
                    />
                </div>

                <flux:input
                    wire:model="date_of_birth"
                    :label="__('Date of birth (dd/mm/yyyy)')"
                    required
                    :placeholder="__('dd/mm/yyyy')"
                    data-test="admit-dob"
                />

                <flux:select wire:model="gender" :label="__('Gender')" required data-test="admit-gender">
                    @foreach (\App\Enums\Gender::cases() as $genderCase)
                        <flux:select.option :value="$genderCase->value">{{ $genderCase->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="nationality"
                    :label="__('Nationality')"
                    :placeholder="__('Indian')"
                    data-test="admit-nationality"
                />

                <flux:input
                    wire:model="mother_tongue"
                    :label="__('Mother tongue')"
                    :placeholder="__('Optional')"
                    data-test="admit-mother-tongue"
                />

                <flux:select wire:model="blood_group" :label="__('Blood group')" data-test="admit-blood-group">
                    <flux:select.option value="">{{ __('Select blood group (optional)') }}</flux:select.option>
                    @foreach (\App\Enums\BloodGroup::cases() as $bloodGroupCase)
                        <flux:select.option :value="$bloodGroupCase->value">{{ $bloodGroupCase->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        {{-- Group 2: Placement --}}
        <flux:card class="space-y-4 p-6">
            <flux:heading size="lg">{{ __('2. Class and section placement') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <flux:select wire:model.live="academic_year_id" :label="__('Academic year')" required data-test="admit-year-select">
                        <flux:select.option value="">{{ __('Select academic year') }}</flux:select.option>
                        @foreach ($this->openAcademicYears as $year)
                            <flux:select.option :value="$year->id">
                                {{ $year->name }}@if ($year->is_current) ({{ __('Current') }})@endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="enrolled_on" />
                </div>

                <flux:select wire:model.live="grade_id" :label="__('Grade')" required data-test="admit-grade-select">
                    <flux:select.option value="">{{ __('Select grade') }}</flux:select.option>
                    @foreach ($this->activeGrades as $grade)
                        <flux:select.option :value="$grade->id">{{ $grade->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="section_id" :label="__('Section')" required data-test="admit-section-select">
                    <flux:select.option value="">{{ __('Select section') }}</flux:select.option>
                    @foreach ($this->sections as $section)
                        <flux:select.option :value="$section->id">{{ $section->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="roll_number"
                    type="number"
                    min="1"
                    max="65535"
                    :label="__('Roll number (optional)')"
                    data-test="admit-roll-number"
                />
            </div>
        </flux:card>

        {{-- Group 3: Guardians --}}
        <flux:card class="space-y-4 p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('3. Guardians (1 to 3)') }}</flux:heading>
                    <flux:subheading>{{ __('At least one guardian and a primary contact mobile number are required.') }}</flux:subheading>
                </div>

                @if (count($guardians) < 3)
                    <flux:button type="button" size="sm" variant="outline" icon="plus" wire:click="addGuardian" data-test="add-guardian-row">
                        {{ __('Add guardian') }}
                    </flux:button>
                @endif
            </div>

            <flux:error name="guardians" />

            <div class="space-y-4">
                @foreach ($guardians as $index => $guardianRow)
                    @php
                        $linkedId = $linkedGuardianIds[$index] ?? null;
                        $suggestions = $this->siblingSuggestions[$index] ?? [];
                    @endphp
                    <div
                        wire:key="admit-guardian-{{ $index }}"
                        class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                        data-test="admit-guardian-row-{{ $index }}"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('Guardian :number', ['number' => $index + 1]) }}
                                </span>

                                @if ($guardianRow['is_primary'])
                                    <flux:badge color="green" size="sm">{{ __('Primary contact') }}</flux:badge>
                                @else
                                    <flux:button type="button" size="xs" variant="ghost" wire:click="setPrimaryGuardian({{ $index }})">
                                        {{ __('Set primary') }}
                                    </flux:button>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($linkedId !== null)
                                    <flux:button type="button" size="xs" variant="outline" wire:click="unlinkSiblingGuardian({{ $index }})">
                                        {{ __('Unlink sibling guardian') }}
                                    </flux:button>
                                @endif

                                @if (count($guardians) > 1)
                                    <flux:button type="button" size="xs" variant="ghost" wire:click="removeGuardian({{ $index }})">
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        @if ($linkedId !== null)
                            <flux:callout variant="success" icon="link" data-test="linked-guardian-callout-{{ $index }}">
                                <flux:callout.heading>{{ __('Linked existing guardian: :label', ['label' => $linkedGuardianLabels[$index] ?? $guardianRow['name']]) }}</flux:callout.heading>
                            </flux:callout>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <flux:input
                                wire:model="guardians.{{ $index }}.name"
                                :label="__('Guardian name')"
                                :disabled="$linkedId !== null"
                                required
                                data-test="guardian-name-{{ $index }}"
                            />

                            <flux:input
                                wire:model.live.debounce.300ms="guardians.{{ $index }}.mobile"
                                :label="__('Mobile number')"
                                :disabled="$linkedId !== null"
                                :placeholder="__('10-digit Indian mobile')"
                                data-test="guardian-mobile-{{ $index }}"
                            />

                            <flux:select
                                wire:model="guardians.{{ $index }}.relationship"
                                :label="__('Relationship')"
                                required
                                data-test="guardian-relationship-{{ $index }}"
                            >
                                @foreach (\App\Enums\GuardianRelationship::cases() as $rel)
                                    <flux:select.option :value="$rel->value">{{ $rel->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input
                                wire:model="guardians.{{ $index }}.email"
                                type="email"
                                :label="__('Email (optional)')"
                                :disabled="$linkedId !== null"
                            />

                            <flux:input
                                wire:model="guardians.{{ $index }}.occupation"
                                :label="__('Occupation (optional)')"
                                :disabled="$linkedId !== null"
                            />
                        </div>

                        <flux:error name="guardians.{{ $index }}.name" />
                        <flux:error name="guardians.{{ $index }}.mobile" />
                        <flux:error name="guardians.{{ $index }}.relationship" />

                        @if ($linkedId === null && $suggestions !== [])
                            <div class="space-y-2 rounded-md bg-amber-50 p-3 dark:bg-amber-950/40" data-test="sibling-suggestions-{{ $index }}">
                                <div class="text-xs font-medium text-amber-800 dark:text-amber-200">
                                    {{ __('Matching guardian already exists in this school:') }}
                                </div>
                                @foreach ($suggestions as $suggestion)
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span>{{ $suggestion['label'] }}</span>
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            wire:click="linkSiblingGuardian({{ $index }}, '{{ $suggestion['id'] }}')"
                                            data-test="link-sibling-{{ $index }}-{{ $suggestion['id'] }}"
                                        >
                                            {{ __('Link instead?') }}
                                        </flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>

        {{-- Group 4: Address & previous school --}}
        <flux:card class="space-y-4 p-6">
            <flux:heading size="lg">{{ __('4. Address and previous schooling') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:input wire:model="address_line" :label="__('Address line')" />
                </div>

                <flux:input wire:model="city" :label="__('City / Village')" />

                <flux:select wire:model="state" :label="__('State / UT')">
                    <flux:select.option value="">{{ __('Select state (optional)') }}</flux:select.option>
                    @foreach (\App\Enums\IndianState::cases() as $stateCase)
                        <flux:select.option :value="$stateCase->value">{{ $stateCase->value }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="pincode" :label="__('PIN code')" :placeholder="__('6 digits')" />

                <flux:input wire:model="previous_school" :label="__('Previous school (optional)')" />

                <flux:input wire:model="previous_tc_no" :label="__('Previous TC number (optional)')" />
            </div>
        </flux:card>

        {{-- Group 5: Government IDs & consent --}}
        <flux:card class="space-y-4 p-6">
            <flux:heading size="lg">{{ __('5. Statutory details and consent') }}</flux:heading>

            @if ($this->canViewSensitive)
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:select wire:model="social_category" :label="__('Social category')" data-test="admit-social-category">
                        <flux:select.option value="">{{ __('Select category (optional)') }}</flux:select.option>
                        @foreach (\App\Enums\SocialCategory::cases() as $categoryCase)
                            <flux:select.option :value="$categoryCase->value">{{ $categoryCase->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="religion" :label="__('Religion (optional)')" data-test="admit-religion" />

                    <flux:input wire:model="apaar_id" :label="__('APAAR ID (12 digits, optional)')" data-test="admit-apaar-id" />

                    <flux:input wire:model="pen" :label="__('UDISE+ PEN (11 digits, optional)')" data-test="admit-pen" />

                    <flux:input wire:model="aadhaar_last4" :label="__('Aadhaar (last 4 digits, optional)')" maxlength="4" data-test="admit-aadhaar-last4" />
                </div>
            @endif

            <div class="pt-2">
                <flux:checkbox
                    wire:model="consent_confirmed"
                    :label="__('Parent/guardian consent obtained')"
                    data-test="admit-consent-checkbox"
                />
                <flux:error name="consent_confirmed" />
            </div>
        </flux:card>

        <div class="flex justify-end gap-3">
            <flux:button
                type="button"
                variant="ghost"
                :href="route('students.index', ['school' => $this->school->id])"
                wire:navigate
            >
                {{ __('Cancel') }}
            </flux:button>

            <flux:button type="submit" variant="primary" data-test="admit-submit-button">
                {{ __('Admit student') }}
            </flux:button>
        </div>
    </form>
</div>
