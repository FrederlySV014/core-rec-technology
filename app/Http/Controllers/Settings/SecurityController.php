<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Modules\Access\Services\PrivilegeService;
use App\Modules\Audit\Models\CentralAuditLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, PrivilegeService $privilegeService): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'isPrivileged' => $privilegeService->isPrivileged($request->user()),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        // Query active browser sessions
        $currentSessionId = $request->session()->getId();
        $sessions = collect(
            DB::connection('central')->table('sessions')
                ->where('user_id', $request->user()->id)
                ->orderBy('last_activity', 'desc')
                ->get()
        )->map(fn ($session) => [
            'id' => md5((string) $session->id),
            'ip_address' => $session->ip_address,
            'is_current_device' => $session->id === $currentSessionId,
            'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            'agent' => $session->user_agent,
        ])->all();

        $props['sessions'] = $sessions;

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->password,
        ]);

        CentralAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.password_changed',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }

    /**
     * Log out from other browser sessions on other devices.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        Auth::logoutOtherDevices((string) $request->input('password'));

        $currentSessionId = $request->session()->getId();

        DB::connection('central')->table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        CentralAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'auth.other_sessions_terminated',
            'auditable_type' => $request->user()->getMorphClass(),
            'auditable_id' => (string) $request->user()->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Otras sesiones cerradas exitosamente.')]);

        return back();
    }

    /**
     * Disable two-factor authentication, unless the user holds privileged roles/permissions.
     */
    public function disableTwoFactorAuthentication(Request $request, PrivilegeService $privilegeService, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $user = $request->user();

        if ($privilegeService->isPrivileged($user)) {
            throw ValidationException::withMessages([
                'two_factor' => 'El segundo factor de autenticación es obligatorio para cuentas con privilegios administrativos.',
            ]);
        }

        $disable($user);

        CentralAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.2fa_disabled',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Autenticación en dos pasos desactivada exitosamente.',
        ]);

        return back();
    }
}
