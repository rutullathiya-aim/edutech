<?php

namespace App\Livewire\Invitations;

use App\Actions\Staff\AcceptInvitation;
use App\Concerns\PasswordValidationRules;
use App\Exceptions\InvalidInvitationException;
use App\Models\Invitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Invitation|null $invitation
 * @property-read string $state
 */
#[Layout('layouts::auth.card')]
#[Title('Accept invitation')]
class Accept extends Component
{
    use PasswordValidationRules;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $terms = false;

    /**
     * Prefill invitee name for new accounts or store intended redirect URL for returning users.
     */
    public function mount(): void
    {
        $invitation = $this->invitation;

        if (! $invitation instanceof Invitation) {
            session()->forget('pending_invitation_token');

            return;
        }

        if ($this->state === 'create_account') {
            $this->name = $invitation->name;
        } elseif ($this->state === 'must_login') {
            session()->put('url.intended', route('invitations.accept'));
        }
    }

    /**
     * Resolve the currently pending invitation from the session token, if valid and active.
     */
    #[Computed]
    public function invitation(): ?Invitation
    {
        $rawToken = session('pending_invitation_token');

        if (! is_string($rawToken) || trim($rawToken) === '') {
            return null;
        }

        $invitation = Invitation::findPendingByToken($rawToken);

        if (! $invitation instanceof Invitation) {
            return null;
        }

        $invitation->loadMissing('school');

        if (! ($invitation->school instanceof School) || ! $invitation->school->isActive()) {
            return null;
        }

        return $invitation;
    }

    /**
     * Determine which of the four invitation screens (or invalid fallback) applies.
     */
    #[Computed]
    public function state(): string
    {
        $invitation = $this->invitation;

        if (! $invitation instanceof Invitation) {
            return 'invalid';
        }

        $invitedEmail = Str::lower(trim((string) $invitation->email));
        $currentUser = auth()->user();

        if ($currentUser instanceof User) {
            return Str::lower((string) $currentUser->email) === $invitedEmail
                ? 'existing_logged_in'
                : 'wrong_user';
        }

        return User::where('email', $invitedEmail)->exists()
            ? 'must_login'
            : 'create_account';
    }

    /**
     * Handle Case A: register a new user under the invitation's email and accept the invitation.
     */
    public function registerAndAccept(AcceptInvitation $acceptInvitation): void
    {
        if ($this->state !== 'create_account') {
            $this->reset('password', 'password_confirmation');

            if ($this->state === 'invalid') {
                session()->forget('pending_invitation_token');

                return;
            }

            $this->addError('invitation', __('Please sign in with the invited account to accept this invitation.'));

            return;
        }

        $rawToken = session('pending_invitation_token');

        if (! is_string($rawToken) || $rawToken === '') {
            $this->invalidateSessionInvitation();

            return;
        }

        $this->name = trim($this->name);

        try {
            /** @var array{name: string, password: string} $validated */
            $validated = $this->validate([
                'name' => ['required', 'string', 'max:100'],
                'password' => $this->passwordRules(),
                'terms' => ['accepted'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            throw $e;
        }

        try {
            $result = $acceptInvitation->handle($rawToken, null, $validated);
        } catch (InvalidInvitationException) {
            $this->invalidateSessionInvitation();

            return;
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            throw $e;
        } catch (UniqueConstraintViolationException) {
            $this->reset('password', 'password_confirmation');
            $this->addError('invitation', __('You already have an account. Log in to accept.'));

            return;
        }

        $this->reset('password', 'password_confirmation');

        Auth::login($result['user']);
        session()->regenerate();
        session()->forget(['pending_invitation_token', 'url.intended']);

        $this->redirectRoute('dashboard', ['school' => $result['school']->id]);
    }

    /**
     * Handle Case C: accept a pending invitation as the authenticated matching user.
     */
    public function acceptExisting(AcceptInvitation $acceptInvitation): void
    {
        $currentUser = auth()->user();

        if ($this->state !== 'existing_logged_in' || ! $currentUser instanceof User) {
            if ($this->state === 'invalid') {
                $this->invalidateSessionInvitation();

                return;
            }

            $this->addError('invitation', __('You are not signed in with the invited email address.'));

            return;
        }

        $rawToken = session('pending_invitation_token');

        if (! is_string($rawToken) || $rawToken === '') {
            $this->invalidateSessionInvitation();

            return;
        }

        try {
            $result = $acceptInvitation->handle($rawToken, $currentUser);
        } catch (InvalidInvitationException) {
            $this->invalidateSessionInvitation();

            return;
        }

        session()->forget(['pending_invitation_token', 'url.intended']);

        $this->redirectRoute('dashboard', ['school' => $result['school']->id]);
    }

    /**
     * Clear the stale invitation token and force the view to fall back to the generic invalid screen.
     */
    private function invalidateSessionInvitation(): void
    {
        $this->reset('password', 'password_confirmation');
        session()->forget('pending_invitation_token');
        unset($this->invitation, $this->state);
    }

    /**
     * Render the invitation acceptance component view.
     */
    public function render(): View
    {
        return view('livewire.invitations.accept');
    }
}
