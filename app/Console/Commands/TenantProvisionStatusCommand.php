<?php

namespace App\Console\Commands;

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class TenantProvisionStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:provision-status {company : ID o slug de la empresa}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consulta el estado técnico de aprovisionamiento de una empresa sin abrir conexiones comerciales';

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

        $status = $service->getStatus($company);

        $this->info("Estado de Aprovisionamiento para: [{$company->legal_name}] (ID: {$company->id})");

        $this->table(['Propiedad', 'Valor'], [
            ['Estado Técnico', $status['status']],
            ['¿Está Lista?', $status['is_ready'] ? 'Sí' : 'No'],
            ['Base de Datos', $status['database'] ?: 'No asignada'],
            ['Host / Puerto', $status['host'] ? "{$status['host']}:{$status['port']}" : 'N/A'],
            ['Usuario de Aplicación', $status['app_username'] ?: 'N/A'],
            ['Cantidad de Registros Log', (string) $status['logs_count']],
            ['Última Etapa Ejecutada', $status['last_log'] ? "{$status['last_log']['stage']} ({$status['last_log']['status']})" : 'Ninguna'],
            ['Último Error', $status['last_log']['error_message'] ?? 'Ninguno'],
        ]);

        return self::SUCCESS;
    }
}
