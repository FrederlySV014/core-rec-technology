<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PlatformProvisioningController extends Controller
{
    /**
     * Consult provisioning status without opening commercial connections.
     */
    public function status(Company $company, TenantProvisioningService $service): JsonResponse
    {
        $status = $service->getStatus($company);

        return response()->json([
            'status' => 'success',
            'company' => [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'slug' => $company->slug,
            ],
            'provisioning' => $status,
        ]);
    }

    /**
     * Initiate initial provisioning for a company.
     */
    public function provision(Request $request, Company $company, TenantProvisioningService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $connection = $service->provision($company, $actor);

            return response()->json([
                'status' => 'success',
                'message' => 'Base comercial aprovisionada y verificada exitosamente.',
                'connection' => [
                    'database' => $connection->database,
                    'host' => $connection->host,
                    'port' => $connection->port,
                    'app_username' => $connection->app_username,
                    'schema' => $connection->schema,
                ],
            ]);
        } catch (ProvisioningException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error inesperado durante el aprovisionamiento técnico.',
            ], 500);
        }
    }

    /**
     * Retry a failed or incomplete provisioning for a company.
     */
    public function retry(Request $request, Company $company, TenantProvisioningService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $force = (bool) $request->boolean('force');

        try {
            $connection = $service->retry($company, $actor, force: $force);

            return response()->json([
                'status' => 'success',
                'message' => 'Reintento de aprovisionamiento completado y verificado exitosamente.',
                'connection' => [
                    'database' => $connection->database,
                    'host' => $connection->host,
                    'port' => $connection->port,
                    'app_username' => $connection->app_username,
                    'schema' => $connection->schema,
                ],
            ]);
        } catch (ProvisioningException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error inesperado durante el reintento de aprovisionamiento.',
            ], 500);
        }
    }
}
