<?php

namespace App\Livewire\Schools;

use App\Actions\Attendance\UpdateWeeklyOffDays;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read School $school
 * @property-read array<int, string> $weekdayOptions
 */
#[Title('School settings')]
class Settings extends Component
{
    /**
     * Selected ISO-8601 weekday numbers (1 = Monday .. 7 = Sunday) mapped to boolean toggles.
     *
     * @var array<int, bool>
     */
    public array $weeklyOffMap = [];

    /**
     * Authorize school settings management and load the school's current weekly off days.
     */
    public function mount(): void
    {
        $school = CurrentSchool::getOrFail();
        $this->authorize('manageSettings', $school);

        $this->syncWeeklyOffMap($school->weeklyOffDays());
    }

    /**
     * Get the current school tenant.
     */
    #[Computed]
    public function school(): School
    {
        return CurrentSchool::getOrFail();
    }

    /**
     * Get the ordered ISO-8601 weekday labels keyed by weekday number (1 = Monday .. 7 = Sunday).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function weekdayOptions(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }

    /**
     * Save the school's configured weekly off days.
     */
    public function save(UpdateWeeklyOffDays $updateWeeklyOffDays): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $selectedDays = [];

        foreach ($this->weekdayOptions as $dayNumber => $label) {
            if (! empty($this->weeklyOffMap[$dayNumber])) {
                $selectedDays[] = $dayNumber;
            }
        }

        $updatedSchool = $updateWeeklyOffDays->handle($actor, $selectedDays);
        $this->syncWeeklyOffMap($updatedSchool->weeklyOffDays());

        unset($this->school);

        Flux::toast(
            variant: 'success',
            text: __('Weekly off days updated.'),
        );
    }

    /**
     * Sync the checkbox map state from a list of ISO weekday integers.
     *
     * @param  list<int>  $weeklyOffDays
     */
    private function syncWeeklyOffMap(array $weeklyOffDays): void
    {
        $map = [];

        foreach (array_keys($this->weekdayOptions) as $dayNumber) {
            $map[$dayNumber] = in_array($dayNumber, $weeklyOffDays, true);
        }

        $this->weeklyOffMap = $map;
    }

    public function render(): View
    {
        return view('livewire.schools.settings');
    }
}
