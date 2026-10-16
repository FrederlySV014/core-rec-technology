<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Services\CompanyAuthorizationService;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    public function __construct(
        protected CompanyAuthorizationService $companyAuth
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyParam = $request->route('company');

        if ($companyParam instanceof Company) {
            $company = $companyParam;
        } elseif (is_string($companyParam)) {
            $company = Company::where('slug', $companyParam)->first();
        } else {
            abort(404, 'Empresa no encontrada o sin acceso.');
        }

        if (! $company instanceof Company || $company->administrative_status !== CompanyStatus::Active) {
            // Never expose company existence or state to unauthorized callers
            abort(404, 'Empresa no encontrada o sin acceso.');
        }

        $user = $request->user();
        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            abort(404, 'Empresa no encontrada o sin acceso.');
        }

        $context = $this->companyAuth->resolveContext($user, $company);
        if ($context === null) {
            // Unassigned or suspended membership: generic 404 without leaking data
            abort(404, 'Empresa no encontrada o sin acceso.');
        }

        // Bind strictly to current request lifecycle without polluting session across tabs
        app()->instance(CompanyContext::class, $context);
        $request->attributes->set('company_context', $context);

        return $next($request);
    }
}
