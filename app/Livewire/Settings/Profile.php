<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Notifications\EmailChangedNotification;
use App\Support\MobileNumber;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public ?string $mobile = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email ?? '';
        $this->mobile = $user->mobile;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        if (config('fortify.lowercase_usernames')) {
            $this->email = Str::lower($this->email);
        }

        if ($this->mobile !== null && trim($this->mobile) !== '') {
            $this->mobile = MobileNumber::normalize($this->mobile);
        } else {
            $this->mobile = null;
        }

        $originalEmail = $user->email;

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email') || $user->isDirty('mobile')) {
            $confirmedAt = now()->unix() - (int) session('auth.password_confirmed_at', 0);
            $timeout = (int) config('auth.password_timeout', 900);

            if ($confirmedAt > $timeout) {
                session()->put('url.intended', route('profile.edit'));
                $this->redirect(route('password.confirm'));

                return;
            }
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty('mobile')) {
            $user->mobile_verified_at = null;
        }

        $user->save();

        if ($originalEmail !== null && $user->email !== $originalEmail) {
            Notification::route('mail', $originalEmail)->notify(
                new EmailChangedNotification($originalEmail, $user->email)
            );
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('schools.index', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        if ($user->hasAnyMembership()) {
            return false;
        }

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
