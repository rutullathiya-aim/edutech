<?php

namespace App\Listeners;

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
        $email = $event->credentials['email'] ?? $event->credentials['username'] ?? null;

        activity('auth')
            ->event('failed_login')
            ->withProperty('email', $email)
            ->log('Failed login attempt');
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
