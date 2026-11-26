<div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Import students') }}</flux:heading>
            <flux:subheading>{{ __('Bulk admit students, guardians, and class enrollments from a validated Excel or CSV spreadsheet.') }}</flux:subheading>
        </div>

        <flux:button
            variant="ghost"
            :href="route('students.index', ['school' => $this->school->id])"
            wire:navigate
            data-test="back-to-students-button"
        >
            {{ __('Back to students') }}
        </flux:button>
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="import-form-error-summary">
            <flux:callout.heading>{{ __('Could not proceed with the import') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-sm">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Step 1: Target Year & Template --}}
    <flux:card class="space-y-4 p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full sm:max-w-xs">
                <flux:select
                    wire:model.live="academic_year_id"
                    :label="__('1. Target academic year')"
                    required
                    data-test="import-academic-year"
                >
                    <flux:select.option value="">{{ __('Select open academic year') }}</flux:select.option>
                    @foreach ($this->openAcademicYears as $year)
                        <flux:select.option :value="$year->id" wire:key="import-year-{{ $year->id }}">
                            {{ $year->name }}@if ($year->is_current) ({{ __('Current') }})@endif
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:button
                variant="outline"
                icon="arrow-down-tray"
                wire:click="downloadTemplate"
                wire:loading.attr="disabled"
                data-test="download-template-button"
            >
                {{ __('Download Excel template (.xlsx)') }}
            </flux:button>
        </div>

        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('The template includes this school’s active grades and sections, sample columns, dd/mm/yyyy date examples, and limits (up to 1,000 rows, max 5 MB).') }}
        </flux:text>
    </flux:card>

    {{-- Step 2: Upload & Dry Run --}}
    <flux:card class="space-y-4 p-6">
        <flux:heading size="lg">{{ __('2. Upload and validate spreadsheet') }}</flux:heading>

        <form wire:submit="dryRun" class="space-y-4">
            <flux:input
                type="file"
                wire:model="file"
                accept=".xlsx,.csv"
                :label="__('Spreadsheet file (.xlsx or .csv, max 5 MB)')"
                data-test="import-file-input"
            />

            <div wire:loading wire:target="file" class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Uploading file to temporary secure storage...') }}
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="file,dryRun,confirmImport"
                    data-test="dry-run-button"
                >
                    {{ __('Validate file (dry run)') }}
                </flux:button>
            </div>
        </form>
    </flux:card>

    {{-- Step 3: Dry Run Summary & Confirm --}}
    @if ($hasDryRun)
        <flux:card class="space-y-5 p-6" data-test="dry-run-summary">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading size="lg">{{ __('3. Validation summary') }}</flux:heading>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="zinc" size="sm" data-test="summary-total-rows">
                        {{ __('Total: :count', ['count' => $totalRows]) }}
                    </flux:badge>
                    <flux:badge color="green" size="sm" data-test="summary-valid-rows">
                        {{ __('Valid: :count', ['count' => $validCount]) }}
                    </flux:badge>
                    <flux:badge :color="$invalidCount > 0 ? 'red' : 'zinc'" size="sm" data-test="summary-invalid-rows">
                        {{ __('Errors: :count', ['count' => $invalidCount]) }}
                    </flux:badge>
                </div>
            </div>

            @if ($invalidCount > 0)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('Fix all validation errors before importing') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Imports are all-or-nothing. Download the error report below, fix the flagged rows in your spreadsheet, and validate again.') }}
                    </flux:callout.text>
                </flux:callout>

                <div>
                    <flux:button
                        variant="outline"
                        icon="arrow-down-tray"
                        wire:click="downloadErrorReport"
                        wire:loading.attr="disabled"
                        data-test="download-error-report-button"
                    >
                        {{ __('Download error report (.xlsx)') }}
                    </flux:button>
                </div>

                <flux:table data-test="import-errors-table">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Row') }}</flux:table.column>
                        <flux:table.column>{{ __('Column') }}</flux:table.column>
                        <flux:table.column>{{ __('Issue') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($errorsList as $index => $errorItem)
                            <flux:table.row wire:key="import-error-{{ $index }}">
                                <flux:table.cell class="font-mono text-xs">{{ $errorItem['row'] }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $errorItem['column'] }}</flux:table.cell>
                                <flux:table.cell>{{ $errorItem['message'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @elseif ($validCount > 0)
                <flux:callout variant="success" icon="check-circle" data-test="dry-run-passed-callout">
                    <flux:callout.heading>{{ __('All :count row(s) passed validation', ['count' => $validCount]) }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Confirm guardian DPDP consent below to admit all students, create or reuse guardians, and record class enrollments in a single transaction.') }}
                    </flux:callout.text>
                </flux:callout>

                <form wire:submit="confirmImport" class="space-y-4">
                    <flux:checkbox
                        wire:model="consent_confirmed"
                        :label="__('I confirm parental or guardian consent has been obtained and documented for all students in this upload.')"
                        data-test="import-consent-checkbox"
                    />
                    <flux:error name="consent_confirmed" />

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <flux:button
                            type="submit"
                            variant="primary"
                            wire:loading.attr="disabled"
                            wire:target="confirmImport,file"
                            data-test="confirm-import-button"
                        >
                            {{ __('Confirm and import :count student(s)', ['count' => $validCount]) }}
                        </flux:button>
                    </div>
                </form>
            @endif
        </flux:card>
    @endif
</div>
