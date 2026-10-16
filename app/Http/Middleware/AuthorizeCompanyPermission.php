<?php

namespace App\Http\Middleware;

use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Services\CompanyAuthorizationService;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeCompanyPermission
{
    public function __construct(
        protected CompanyAuthorizationService $companyAuth
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permissionSlug, ?string $mode = null): Response
    {
        /** @var CompanyContext|null $context */
        $context = $request->attributes->get('company_context') ?? (app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null);

        if ($context === null) {
            abort(403, 'Contexto empresarial no inicializado.');
        }

        $validity = $context->effectiveLicense->validityStatus;

        $permissions = explode('|', $permissionSlug);
        $hasPermission = false;
        foreach ($permissions as $perm) {
            if ($context->hasPermission(trim($perm))) {
                $hasPermission = true;
                break;
            }
        }

        if ($mode === 'security_admin') {
            // Security/Identity administrative actions are permitted during Active, GracePeriod, or Expired states,
            // but strictly forbidden if the company/license is administratively suspended or revoked.
            if ($validity !== LicenseValidityStatus::Active && $validity !== LicenseValidityStatus::GracePeriod && $validity !== LicenseValidityStatus::Expired) {
                abort(403, 'Acceso denegado: el servicio de la empresa se encuentra suspendido o revocado.');
            }

            // Membership must hold at least one of the required permissions
            if (! $hasPermission) {
                abort(403, 'Permiso administrativo insuficiente para gestionar membresías o roles.');
            }

            return $next($request);
        }

        // Standard commercial operations require Active or GracePeriod license
        if ($validity !== LicenseValidityStatus::Active && $validity !== LicenseValidityStatus::GracePeriod) {
            abort(403, 'La empresa no cuenta con una licencia vigente para operar.');
        }

        if (! $hasPermission) {
            abort(403, 'Permiso comercial insuficiente o módulo no habilitado en la licencia.');
        }

        $explicitMutable = match ($mode) {
            'mutable' => true,
            'read_only' => false,
            default => null,
        };

        if ($context->isReadOnly && $this->companyAuth->isActionMutable($permissionSlug, $explicitMutable)) {
            abort(403, 'Operación comercial denegada: la empresa se encuentra en período de gracia (modo solo lectura).');
        }

        return $next($request);
    }
}
