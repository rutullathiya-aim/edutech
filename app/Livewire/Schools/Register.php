<?php

namespace App\Livewire\Schools;

use App\Actions\Schools\RegisterSchool;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\SchoolValidationRules;
use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use App\Support\MobileNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::auth.card')]
#[Title('Register your school')]
class Register extends Component
{
    use PasswordValidationRules, ProfileValidationRules, SchoolValidationRules;

    public string $school_name = '';

    public ?string $state = '';

    public string $city = '';

    public ?string $board = '';

    public string $name = '';

    public string $email = '';

    public ?string $mobile = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $terms = false;

    public string $contact_hp_x9k = '';

    /**
     * Validate input, enforce anti-bot controls, and register the new school and owner.
     */
    public function register(RegisterSchool $registerSchool): void
    {
        if ($this->contact_hp_x9k !== '') {
            return;
        }

        $ip = request()->ip() ?? 'unknown';
        $normalizedEmail = Str::lower(trim($this->email));

        $successKey = 'register-school-success:'.$ip;
        $attemptKey = 'register-school-attempt:'.$ip.'|'.$normalizedEmail;

        if (RateLimiter::tooManyAttempts($successKey, 3)) {
            $this->addThrottleError($successKey);

            return;
        }

        if (RateLimiter::tooManyAttempts($attemptKey, 10)) {
            $this->addThrottleError($attemptKey);

            return;
        }

        RateLimiter::hit($attemptKey, 3600);

        $this->school_name = trim($this->school_name);
        $this->state = filled($this->state) ? trim((string) $this->state) : null;
        $this->city = trim($this->city);
        $this->board = filled($this->board) ? trim((string) $this->board) : null;
        $this->name = trim($this->name);
        $this->email = $normalizedEmail;
        $this->mobile = filled($this->mobile) ? MobileNumber::normalize((string) $this->mobile) : null;

        try {
            /** @var array{
             *     school_name: string,
             *     state: string,
             *     city: string,
             *     board: string|null,
             *     name: string,
             *     email: string,
             *     mobile: string,
             *     password: string,
             * } $validated
             */
            $validated = $this->validate([
                'school_name' => $this->schoolNameRules(),
                'state' => $this->schoolStateRules(required: true),
                'city' => $this->schoolCityRules(required: true),
                'board' => $this->schoolBoardRules(),
                'name' => $this->nameRules(),
                'email' => $this->emailRules(),
                'mobile' => $this->mobileRules(required: true),
                'password' => $this->passwordRules(),
                'terms' => ['accepted'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            throw $e;
        }

        try {
            ['user' => $user] = $registerSchool->handle($validated);
        } catch (UniqueConstraintViolationException $e) {
            $this->reset('password', 'password_confirmation');

            $attribute = $this->resolveUniqueViolationAttribute($e);

            $this->addError($attribute, __('validation.unique', ['attribute' => $attribute]));

            return;
        }

        RateLimiter::hit($successKey, 86400);

        $this->reset('password', 'password_confirmation');

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('verification.notice');
    }

    /**
     * Get all Indian states for the dropdown.
     *
     * @return list<IndianState>
     */
    #[Computed]
    public function states(): array
    {
        return IndianState::cases();
    }

    /**
     * Get all school boards for the optional dropdown.
     *
     * @return list<SchoolBoard>
     */
    #[Computed]
    public function boards(): array
    {
        return SchoolBoard::cases();
    }

    /**
     * Render the school registration view.
     */
    public function render(): View
    {
        return view('livewire.schools.register');
    }

    /**
     * Add a rate-limit validation error for the exhausted limiter key.
     */
    private function addThrottleError(string $key): void
    {
        $seconds = RateLimiter::availableIn($key);

        $this->addError('throttle', __('Too many registration attempts. Please try again in :minutes minutes.', [
            'minutes' => max(1, (int) ceil($seconds / 60)),
        ]));
    }

    /**
     * Determine whether a unique constraint violation occurred on mobile or email.
     */
    private function resolveUniqueViolationAttribute(UniqueConstraintViolationException $exception): string
    {
        if (
            in_array('mobile', $exception->columns, true)
            || str_contains(strtolower($exception->getMessage()), 'mobile')
        ) {
            return 'mobile';
        }

        return 'email';
    }
}
