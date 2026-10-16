<?php

namespace App\Console\Commands;

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Jobs\ProvisionTenantDatabaseJob;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Throwable;

class TenantProvisionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:provision 
                            {company : ID o slug de la empresa}
                            {--retry : Reintentar un aprovisionamiento previo fallido o incompleto}
                            {--force : Forzar la recuperación de una ejecución bloqueada en curso}
                            {--queue : Despachar la tarea a la cola en segundo plano en lugar de ejecutar síncronamente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aprovisiona o reintenta la base de datos comercial independiente para una empresa registrada';

    /**
     * Execute the console command.
     */
    public function handle(TenantProvisioningService $service): int
    {
        $companyIdentifier = $this->argument('company');

        /** @var Company|null $company */
        $company = is_numeric($companyIdentifier)
            ? Company::find($companyIdentifier)
            : Company::where('slug', $companyIdentifier)->first();

        if (! $company) {
            $this->error("Empresa no encontrada con el identificador [{$companyIdentifier}].");

            return self::FAILURE;
        }

        $isRetry = (bool) $this->option('retry');
        $force = (bool) $this->option('force');
        $useQueue = (bool) $this->option('queue');

        $this->info("Iniciando proceso de aprovisionamiento para la empresa: [{$company->legal_name}] (ID: {$company->id})...");

        if ($useQueue) {
            ProvisionTenantDatabaseJob::dispatch($company->id, null, $isRetry, $force);
            $this->info('Tarea de aprovisionamiento despachada a la cola exitosamente.');
            $this->comment('Asegúrate de tener un worker ejecutándose: php artisan queue:work');

            return self::SUCCESS;
        }

        try {
            $connection = $isRetry
                ? $service->retry($company, force: $force)
                : $service->provision($company, force: $force);

            $this->info('✓ Aprovisionamiento completado y verificado exitosamente.');
            $this->table(['Parámetro', 'Valor'], [
                ['Empresa', $company->legal_name],
                ['Estado Técnico', $company->fresh()->technical_provisioning_status->value],
                ['Base de Datos', $connection->database],
                ['Host / Puerto', "{$connection->host}:{$connection->port}"],
                ['Usuario App (DML)', $connection->app_username],
                ['Usuario Migraciones (DDL)', $connection->migration_username],
                ['Esquema', $connection->schema],
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('✗ Error durante el aprovisionamiento.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
    }
}
