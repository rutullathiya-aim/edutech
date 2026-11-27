<?php

namespace App\Livewire\Setup;

use App\Actions\Attendance\AddHoliday;
use App\Actions\Attendance\RemoveHoliday;
use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, AcademicYear> $academicYears
 * @property-read AcademicYear|null $selectedAcademicYear
 * @property-read bool $isSelectedYearClosed
 * @property-read bool $canManage
 * @property-read Collection<int, Holiday> $holidays
 */
#[Title('Holidays')]
class Holidays extends Component
{
    public string $academic_year_id = '';

    public string $date = '';

    public string $name = '';

    /**
     * Authorize holiday viewing and default the year selector to the current academic year.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Holiday::class);

        $years = $this->academicYears();
        $defaultYear = $years->firstWhere('is_current', true) ?? $years->first();

        $this->academic_year_id = $defaultYear instanceof AcademicYear ? $defaultYear->id : '';
    }

    /**
     * Get the current school's academic years for the year selector.
     *
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get the currently selected academic year within the current school.
     */
    #[Computed]
    public function selectedAcademicYear(): ?AcademicYear
    {
        if ($this->academic_year_id === '') {
            return null;
        }

        return $this->academicYears()->firstWhere('id', $this->academic_year_id);
    }

    /**
     * Determine whether the selected academic year is closed (read-only).
     */
    #[Computed]
    public function isSelectedYearClosed(): bool
    {
        return $this->selectedAcademicYear()?->isClosed() ?? false;
    }

    /**
     * Determine whether the current user may add or remove planned holidays.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Holiday::class) ?? false;
    }

    /**
     * Get all planned holidays in the selected academic year ordered chronologically.
     *
     * @return Collection<int, Holiday>
     */
    #[Computed]
    public function holidays(): Collection
    {
        $selectedYear = $this->selectedAcademicYear();

        if (! $selectedYear instanceof AcademicYear) {
            return new Collection;
        }

        return Holiday::query()
            ->where('academic_year_id', $selectedYear->id)
            ->orderBy('date')
            ->get();
    }

    /**
     * Reset errors when switching academic years.
     */
    public function updatedAcademicYearId(): void
    {
        $this->resetValidation();
        unset($this->selectedAcademicYear, $this->isSelectedYearClosed, $this->holidays);
    }

    /**
     * Add a planned holiday to the selected open academic year.
     */
    public function addHoliday(AddHoliday $addHoliday): void
    {
        $this->authorize('create', Holiday::class);

        $selectedYear = $this->selectedAcademicYear();

        if (! $selectedYear instanceof AcademicYear) {
            Flux::toast(variant: 'danger', text: __('Create an academic year before adding holidays.'));

            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $addHoliday->handle($actor, $selectedYear, $this->date, $this->name);

        $this->reset('date', 'name');
        $this->resetValidation();
        unset($this->holidays);

        Flux::toast(
            variant: 'success',
            text: __('Holiday added.'),
        );
    }

    /**
     * Remove a planned holiday from the selected open academic year.
     */
    public function removeHoliday(string $holidayId, RemoveHoliday $removeHoliday): void
    {
        $holiday = Holiday::query()->findOrFail($holidayId);
        $this->authorize('delete', $holiday);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $removeHoliday->handle($actor, $holiday);

        $this->resetValidation();
        unset($this->holidays);

        Flux::toast(
            variant: 'success',
            text: __('Holiday removed.'),
        );
    }

    public function render(): View
    {
        return view('livewire.setup.holidays');
    }
}
