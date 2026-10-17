<?php

namespace App\Console\Commands;

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate 
                            {company : ID o slug de la empresa}
                            {--database=tenant_migration_runner : Nombre de la conexión dinámica a utilizar para ejecutar las migraciones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta las migraciones comerciales del ERP sobre la base de datos de una empresa específica utilizando credenciales de migración DDL';

    /**
     * Execute the console command.
     */
    public function handle(TenantProvisioningService $provisioningService): int
    {
        $companyIdentifier = $this->argument('company');
        $runnerConnection = (string) ($this->option('database') ?: 'tenant_migration_runner');

        /** @var Company|null $company */
        $company = is_numeric($companyIdentifier)
            ? Company::find($companyIdentifier)
            : Company::where('slug', $companyIdentifier)->first();

        if (! $company) {
            $this->error("Empresa no encontrada con el identificador [{$companyIdentifier}].");

            return self::FAILURE;
        }

        $connectionConfig = $company->databaseConnection;
        if (! $connectionConfig) {
            $this->error("La empresa [{$company->legal_name}] no posee configuración de conexión comercial.");

            return self::FAILURE;
        }

        if ($company->technical_provisioning_status !== ProvisioningStatus::Ready) {
            $this->error("La empresa [{$company->legal_name}] no está en estado 'Ready' (estado actual: {$company->technical_provisioning_status->value}).");

            return self::FAILURE;
        }

        $this->info("Iniciando migración comercial para: [{$company->legal_name}] en base [{$connectionConfig->database}]...");

        try {
            // 1. Acquire exclusive PostgreSQL advisory lock for the company
            $provisioningService->acquireOperationLock($company);

            // 2. Configure dynamic migration runner connection with decrypted DDL credentials
            Config::set("database.connections.{$runnerConnection}", [
                'driver' => 'pgsql',
                'host' => $connectionConfig->host,
                'port' => $connectionConfig->port,
                'database' => $connectionConfig->database,
                'username' => $connectionConfig->migration_username,
                'password' => $connectionConfig->migration_password,
                'schema' => $connectionConfig->schema ?: 'public',
                'sslmode' => 'prefer',
            ]);

            DB::purge($runnerConnection);

            // 3. Verify target database identity matches this company
            $identityRow = DB::connection($runnerConnection)
                ->table('tenant_identity')
                ->where('company_id', $company->id)
                ->first();

            if (! $identityRow) {
                $this->error("Fallo de seguridad: la base de datos [{$connectionConfig->database}] no coincide con el tenant_identity de la empresa ID [{$company->id}].");

                return self::FAILURE;
            }

            // 4. Run tenant migrations strictly on this connection and path
            $exitCode = Artisan::call('migrate', [
                '--database' => $runnerConnection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ], $this->output);

            if ($exitCode !== 0) {
                $this->error('Error durante la ejecución de las migraciones comerciales.');

                return self::FAILURE;
            }

            // 5. Grant DML privileges strictly limited to ERP tables and sequences to app_username
            $dbPdo = $provisioningService->getDatabaseAdminPdo($connectionConfig->database);
            $provisioningService->grantErpPrivileges($dbPdo, $connectionConfig->app_username);

            $this->info("✓ Migraciones comerciales ejecutadas y privilegios de ERP asegurados exitosamente en [{$connectionConfig->database}].");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('✗ Error durante la migración del tenant: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge($runnerConnection);
            $provisioningService->releaseOperationLock($company);
        }
    }
}
