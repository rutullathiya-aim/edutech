<?php

namespace App\Livewire\Schools;

use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Select a school')]
class Index extends Component
{
    /**
     * Redirect directly to the school's dashboard when the user has a single active school.
     */
    public function mount(): void
    {
        $schools = $this->schools();

        if ($schools->count() === 1) {
            /** @var School $school */
            $school = $schools->first();

            $this->redirectRoute('dashboard', ['school' => $school->id]);
        }
    }

    /**
     * Get the authenticated user's active schools.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schools(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->cachedActiveSchools();
    }

    /**
     * Render the school picker view.
     */
    public function render(): View
    {
        return view('livewire.schools.index');
    }
}
