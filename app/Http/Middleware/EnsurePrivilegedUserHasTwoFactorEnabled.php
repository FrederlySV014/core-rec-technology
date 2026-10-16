<?php

namespace App\Http\Middleware;

use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Services\PrivilegeService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedUserHasTwoFactorEnabled
{
    public function __construct(
        protected PrivilegeService $privilegeService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $isPrivileged = false;

        if ($scope === 'platform') {
            $isPrivileged = $this->privilegeService->isPlatformPrivileged($user);
        } elseif ($scope === 'company') {
            /** @var CompanyContext|null $context */
            $context = $request->attributes->get('company_context') ?? (app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null);
            $company = $context?->company;
            $isPrivileged = $this->privilegeService->isCompanyPrivileged($user, $company);
        } else {
            $isPrivileged = $this->privilegeService->isPrivileged($user);
        }

        if (! $isPrivileged) {
            return $next($request);
        }

        // 1. Must have two-factor authentication enabled & confirmed
        if (! $user->hasEnabledTwoFactorAuthentication() || $user->two_factor_confirmed_at === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Su cuenta cuenta con privilegios administrativos o de titularidad. Debe activar y confirmar la autenticación de dos factores (2FA) antes de acceder a este módulo.',
                    'requires_two_factor' => true,
                ], 403);
            }

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Su cuenta cuenta con privilegios administrativos o de titularidad. Por seguridad, es obligatorio configurar y confirmar la autenticación de dos factores (2FA) antes de ingresar a este módulo.',
            ]);

            return redirect()->route('security.edit');
        }

        // 2. Must have cleared the 2FA challenge in this active session
        if (! session('auth.2fa_passed')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Debe completar el desafío de autenticación de dos factores (2FA) para acceder a este ámbito.',
                    'requires_two_factor_challenge' => true,
                ], 403);
            }

            // Set login.id for Fortify challenge handling
            $request->session()->put('login.id', $user->getKey());

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Por favor verifique su código 2FA para acceder al ámbito administrativo.',
            ]);

            return redirect()->route('two-factor.login');
        }

        return $next($request);
    }
}
