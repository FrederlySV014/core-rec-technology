<?php

namespace App\Modules\Audit\Listeners;

use App\Models\User;
use App\Modules\Audit\Models\CentralAuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

class AuthenticationAuditSubscriber
{
    /**
     * Handle user login.
     */
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        CentralAuditLog::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'action' => 'auth.login',
            'auditable_type' => $user instanceof User ? $user->getMorphClass() : null,
            'auditable_id' => $user instanceof User ? (string) $user->id : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'new_values' => [
                'guard' => $event->guard,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Handle user logout.
     */
    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        CentralAuditLog::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'action' => 'auth.logout',
            'auditable_type' => $user instanceof User ? $user->getMorphClass() : null,
            'auditable_id' => $user instanceof User ? (string) $user->id : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'new_values' => [
                'guard' => $event->guard,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Handle failed login attempt.
     */
    public function handleFailed(Failed $event): void
    {
        $user = $event->user;
        $email = isset($event->credentials['email']) ? (string) $event->credentials['email'] : null;

        if ($user === null && $email !== null) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        }

        CentralAuditLog::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'action' => 'auth.failed_login',
            'auditable_type' => $user instanceof User ? $user->getMorphClass() : null,
            'auditable_id' => $user instanceof User ? (string) $user->id : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old_values' => [
                'attempted_email' => $email,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Handle password reset.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        CentralAuditLog::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'action' => 'auth.password_reset',
            'auditable_type' => $user instanceof User ? $user->getMorphClass() : null,
            'auditable_id' => $user instanceof User ? (string) $user->id : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Handle 2FA confirmed.
     */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $user = $event->user;

        CentralAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.2fa_confirmed',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => (string) $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            PasswordReset::class => 'handlePasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
        ];
    }
}
