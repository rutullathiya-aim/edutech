<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    @php
        $student = $this->student;
        $enrollment = $this->activeEnrollment;
        $links = $student->studentGuardians;
    @endphp

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Edit student: :name', ['name' => $student->name]) }}</flux:heading>
            <flux:subheading class="font-mono">{{ $student->admission_no }}</flux:subheading>
        </div>

        <flux:button
            variant="ghost"
            :href="route('students.show', ['school' => $this->school->id, 'student' => $student->id])"
            wire:navigate
        >
            {{ __('Back to profile') }}
        </flux:button>
    </div>

    {{-- Section A: Profile --}}
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

    <form wire:submit="saveProfile" class="space-y-6">
        <flux:card class="space-y-4 p-6">
            <flux:heading size="lg">{{ __('Profile details') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="admission_no" :label="__('Admission number')" required data-test="edit-admission-no" />
                <flux:input wire:model="admission_date" :label="__('Admission date (dd/mm/yyyy)')" required data-test="edit-admission-date" />

                <div class="sm:col-span-2">
                    <flux:input wire:model="name" :label="__('Full name')" required data-test="edit-name" />
                </div>

                <flux:input wire:model="date_of_birth" :label="__('Date of birth (dd/mm/yyyy)')" required data-test="edit-dob" />

                <flux:select wire:model="gender" :label="__('Gender')" required data-test="edit-gender">
                    @foreach (\App\Enums\Gender::cases() as $genderCase)
                        <flux:select.option :value="$genderCase->value">{{ $genderCase->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="nationality" :label="__('Nationality')" />
                <flux:input wire:model="mother_tongue" :label="__('Mother tongue')" />

                <flux:select wire:model="blood_group" :label="__('Blood group')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach (\App\Enums\BloodGroup::cases() as $bloodGroupCase)
                        <flux:select.option :value="$bloodGroupCase->value">{{ $bloodGroupCase->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

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

                <flux:input wire:model="pincode" :label="__('PIN code')" />
                <flux:input wire:model="previous_school" :label="__('Previous school')" />
                <flux:input wire:model="previous_tc_no" :label="__('Previous TC number')" />
            </div>

            @if ($this->canViewSensitive)
                <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="edit-sensitive-fields">
                    <flux:heading size="sm" class="mb-3">{{ __('Statutory and sensitive details') }}</flux:heading>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <flux:select wire:model="social_category" :label="__('Social category')" data-test="edit-social-category">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach (\App\Enums\SocialCategory::cases() as $categoryCase)
                                <flux:select.option :value="$categoryCase->value">{{ $categoryCase->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="religion" :label="__('Religion')" data-test="edit-religion" />
                        <flux:input wire:model="apaar_id" :label="__('APAAR ID')" data-test="edit-apaar-id" />
                        <flux:input wire:model="pen" :label="__('UDISE+ PEN')" data-test="edit-pen" />
                        <flux:input wire:model="aadhaar_last4" :label="__('Aadhaar (last 4 digits)')" maxlength="4" data-test="edit-aadhaar-last4" />
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-2">
                <flux:button type="submit" variant="primary" data-test="save-profile-button">
                    {{ __('Save profile') }}
                </flux:button>
            </div>
        </flux:card>
    </form>

    {{-- Section B: Section transfer within same academic year --}}
    <flux:card class="space-y-4 p-6">
        <flux:heading size="lg">{{ __('Class and section placement') }}</flux:heading>

        @if ($enrollment === null)
            <flux:text>{{ __('This student does not have an active enrollment.') }}</flux:text>
        @elseif ($enrollment->academicYear->isClosed())
            <flux:callout variant="warning" icon="lock-closed">
                <flux:callout.heading>
                    {{ __('Enrollment year :year is closed and read-only.', ['year' => $enrollment->academicYear->name]) }}
                </flux:callout.heading>
            </flux:callout>
        @else
            <form wire:submit="saveSection" class="space-y-4">
                <flux:text class="text-sm">
                    {{ __('Moving sections within academic year :year keeps the enrollment record continuous.', ['year' => $enrollment->academicYear->name]) }}
                </flux:text>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:select wire:model.live="grade_id" :label="__('Grade')" required data-test="edit-section-grade">
                        <flux:select.option value="">{{ __('Select grade') }}</flux:select.option>
                        @foreach ($this->activeGrades as $grade)
                            <flux:select.option :value="$grade->id">{{ $grade->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="section_id" :label="__('Section')" required data-test="edit-section-select">
                        <flux:select.option value="">{{ __('Select section') }}</flux:select.option>
                        @foreach ($this->sections as $section)
                            <flux:select.option :value="$section->id">{{ $section->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="roll_number" type="number" min="1" max="65535" :label="__('Roll number (optional)')" data-test="edit-roll-number" />
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="outline" data-test="save-section-button">
                        {{ __('Update section') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:card>

    {{-- Section C: Guardians --}}
    <flux:card class="space-y-5 p-6">
        <flux:heading size="lg">{{ __('Guardians') }}</flux:heading>

        <div class="space-y-3">
            @foreach ($links as $link)
                @php
                    $guardian = $link->guardian;
                @endphp
                <div
                    wire:key="edit-guardian-link-{{ $link->id }}"
                    class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700"
                    data-test="edit-guardian-row-{{ $guardian->id }}"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $guardian->name }}</span>
                            <flux:badge size="sm" color="zinc">{{ $link->relationship->label() }}</flux:badge>
                            @if ($link->isPrimary())
                                <flux:badge size="sm" color="green">{{ __('Primary contact') }}</flux:badge>
                            @endif
                        </div>

                        <div class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $guardian->mobile ?? __('No mobile') }}
                            @if ($guardian->email !== null)
                                · {{ $guardian->email }}
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if (! $link->isPrimary() && $guardian->mobile !== null)
                            <flux:button
                                size="sm"
                                variant="outline"
                                wire:click="setPrimaryGuardian('{{ $guardian->id }}')"
                                data-test="make-primary-{{ $guardian->id }}"
                            >
                                {{ __('Make primary') }}
                            </flux:button>
                        @endif

                        @if ($links->count() > 1)
                            @if ($link->isPrimary())
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    wire:click="openDetachPrimaryModal('{{ $guardian->id }}')"
                                    data-test="detach-primary-{{ $guardian->id }}"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            @else
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    wire:click="detachGuardian('{{ $guardian->id }}')"
                                    wire:confirm="{{ __('Remove :name from this student?', ['name' => $guardian->name]) }}"
                                    data-test="detach-guardian-{{ $guardian->id }}"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($links->count() < 3)
            <form wire:submit="attachGuardian" class="space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="attach-guardian-form">
                <flux:heading size="sm">{{ __('Add another guardian (max 3)') }}</flux:heading>

                @if ($linkedGuardianId !== null)
                    <flux:callout variant="success" icon="link" class="flex items-center justify-between gap-2">
                        <flux:callout.heading>{{ __('Linked existing guardian: :label', ['label' => $linkedGuardianLabel]) }}</flux:callout.heading>
                        <flux:button type="button" size="xs" variant="outline" wire:click="unlinkExistingGuardian">
                            {{ __('Unlink') }}
                        </flux:button>
                    </flux:callout>
                @endif

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:input wire:model="new_guardian_name" :label="__('Name')" :disabled="$linkedGuardianId !== null" required data-test="attach-guardian-name" />
                    <flux:input wire:model.live.debounce.300ms="new_guardian_mobile" :label="__('Mobile number')" :disabled="$linkedGuardianId !== null" data-test="attach-guardian-mobile" />

                    <flux:select wire:model="new_guardian_relationship" :label="__('Relationship')" required data-test="attach-guardian-relationship">
                        @foreach (\App\Enums\GuardianRelationship::cases() as $rel)
                            <flux:select.option :value="$rel->value">{{ $rel->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="new_guardian_email" type="email" :label="__('Email (optional)')" :disabled="$linkedGuardianId !== null" />
                    <flux:input wire:model="new_guardian_occupation" :label="__('Occupation (optional)')" :disabled="$linkedGuardianId !== null" />

                    <div class="flex items-end pb-1">
                        <flux:checkbox wire:model="new_guardian_is_primary" :label="__('Set as primary contact')" />
                    </div>
                </div>

                @if ($linkedGuardianId === null && $this->siblingSuggestions !== [])
                    <div class="space-y-2 rounded-md bg-amber-50 p-3 dark:bg-amber-950/40" data-test="edit-sibling-suggestions">
                        <div class="text-xs font-medium text-amber-800 dark:text-amber-200">
                            {{ __('Matching guardian in this school:') }}
                        </div>
                        @foreach ($this->siblingSuggestions as $suggestion)
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>{{ $suggestion['label'] }}</span>
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="outline"
                                    wire:click="linkExistingGuardian('{{ $suggestion['id'] }}')"
                                >
                                    {{ __('Link instead?') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end">
                    <flux:button type="submit" variant="outline" data-test="attach-guardian-submit">
                        {{ __('Add guardian') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:card>

    <flux:modal name="detach-primary-guardian-modal" wire:model="showDetachPrimaryModal" class="max-w-md">
        <form wire:submit="confirmDetachPrimary" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Choose a new primary guardian') }}</flux:heading>
                <flux:subheading>{{ __('Select which remaining guardian (with a mobile number) should become the primary contact before removing this link.') }}</flux:subheading>
            </div>

            <flux:select wire:model="replacementPrimaryGuardianId" :label="__('New primary guardian')" required>
                <flux:select.option value="">{{ __('Select guardian') }}</flux:select.option>
                @foreach ($links as $candidateLink)
                    @if ($candidateLink->guardian_id !== $detachingPrimaryGuardianId && $candidateLink->guardian->mobile !== null)
                        <flux:select.option :value="$candidateLink->guardian_id">
                            {{ $candidateLink->guardian->name }} ({{ $candidateLink->guardian->mobile }})
                        </flux:select.option>
                    @endif
                @endforeach
            </flux:select>

            <flux:error name="new_primary_guardian_id" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showDetachPrimaryModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="danger">
                    {{ __('Replace primary and remove') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
