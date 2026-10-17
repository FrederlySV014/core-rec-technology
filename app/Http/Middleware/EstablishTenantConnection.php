<?php

namespace App\Http\Middleware;

use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstablishTenantConnection
{
    public function __construct(
        protected TenantConnectionManager $tenantManager
    ) {}

    /**
     * Handle an incoming request.
     * Establishes the commercial tenant database connection after verifying identity,
     * and guarantees connection teardown upon completion or failure.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var CompanyContext|null $context */
        $context = $request->attributes->get('company_context') ?? (app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null);

        if ($context === null) {
            abort(403, 'Contexto empresarial no inicializado.');
        }

        // Establish, verify and bind the commercial database connection
        $this->tenantManager->connect($context->company);

        try {
            return $next($request);
        } finally {
            // Guarantee full connection release, cache purge and secret blanking
            $this->tenantManager->disconnect();
        }
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantManager->disconnect();
    }
}
