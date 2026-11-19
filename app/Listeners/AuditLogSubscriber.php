<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class AuditLogSubscriber
{
    /**
     * Handle user login.
     */
    public function handleUserLogin(Login $event): void
    {
        if ($event->user instanceof Model) {
            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('login')
                ->log('User logged in');
        }
    }

    /**
     * Handle failed login.
     */
    public function handleFailedLogin(Failed $event): void
    {
        $rawIdentifier = $event->credentials['email'] ?? $event->credentials['username'] ?? null;
        $user = $event->user;

        if (! $user && is_string($rawIdentifier) && filter_var($rawIdentifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', strtolower(trim($rawIdentifier)))->first();
        }

        $activity = activity('auth')->event('failed_login');

        if ($user instanceof Model) {
            $activity->performedOn($user);
        } elseif (is_string($rawIdentifier) && filter_var($rawIdentifier, FILTER_VALIDATE_EMAIL)) {
            $activity->withProperty('attempted_email', $this->maskEmail($rawIdentifier));
        }

        $activity->log('Failed login attempt');
    }

    /**
     * Mask an email address to protect privacy.
     */
    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        $maskedLocal = strlen($local) <= 2
            ? substr($local, 0, 1).'***'
            : substr($local, 0, 2).'***';

        return $maskedLocal.'@'.$domain;
    }

    /**
     * Handle user logout.
     */
    public function handleUserLogout(Logout $event): void
    {
        if ($event->user instanceof Model) {
            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('logout')
                ->log('User logged out');
        }
    }

    /**
     * Handle password reset.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof Model) {
            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('password_reset')
                ->log('Password reset successfully');
        }
    }

    /**
     * Handle 2FA enabled.
     */
    public function handleTwoFactorEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('two_factor_enabled')
            ->log('Two-factor authentication enabled');
    }

    /**
     * Handle 2FA disabled.
     */
    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('two_factor_disabled')
            ->log('Two-factor authentication disabled');
    }

    /**
     * Handle passkey registered.
     */
    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        if ($event->user instanceof Model) {
            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('passkey_added')
                ->withProperty('name', $event->passkey->name ?? null)
                ->log('Passkey added');
        }
    }

    /**
     * Handle passkey deleted.
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        if ($event->user instanceof Model) {
            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('passkey_removed')
                ->withProperty('name', $event->passkey->name ?? null)
                ->log('Passkey removed');
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleUserLogin',
            Failed::class => 'handleFailedLogin',
            Logout::class => 'handleUserLogout',
            PasswordReset::class => 'handlePasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
            PasskeyRegistered::class => 'handlePasskeyRegistered',
            PasskeyDeleted::class => 'handlePasskeyDeleted',
        ];
    }
}
