<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();

        if ($user->hasActiveMemberships()) {
            abort(403, 'Users with active school memberships cannot delete their accounts.');
        }

        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}
