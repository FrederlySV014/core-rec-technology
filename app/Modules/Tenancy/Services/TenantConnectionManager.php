<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Tenancy\Exceptions\TenantConnectionConfigException;
use App\Modules\Tenancy\Exceptions\TenantConnectionFailedException;
use App\Modules\Tenancy\Exceptions\TenantContextException;
use App\Modules\Tenancy\Exceptions\TenantIdentityMismatchException;
use App\Modules\Tenancy\Exceptions\TenantNotProvisionedException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantConnectionManager
{
    /**
     * Currently active and verified company for the tenant connection.
     */
    protected ?Company $activeCompany = null;

    /**
     * Currently verified tenant identity row from the tenant database.
     */
    protected ?object $activeTenantIdentity = null;

    /**
     * Connect and establish the commercial database connection for the authorized company.
     * Validates provisioning state, decrypts application credentials, tests connectivity,
     * and strictly asserts tenant_identity before enabling commercial operations.
     *
     * @throws TenantNotProvisionedException
     * @throws TenantConnectionConfigException
     * @throws TenantConnectionFailedException
     * @throws TenantIdentityMismatchException
     * @throws TenantContextException
     */
    public function connect(Company $company): void
    {
        // 1. Prevent company switching within an active in-flight commercial operation
        if ($this->activeCompany !== null) {
            if ($this->activeCompany->id === $company->id) {
                return;
            }

            throw new TenantContextException(
                "Operación comercial en curso para la empresa [{$this->activeCompany->legal_name}]. No se permite cambiar de contexto durante una operación activa."
            );
        }

        // 2. Validate provisioning status before attempting any infrastructure contact
        if ($company->technical_provisioning_status !== ProvisioningStatus::Ready) {
            throw new TenantNotProvisionedException(
                'La base de datos comercial de la empresa no se encuentra disponible o aún está en proceso de aprovisionamiento.'
            );
        }

        // 3. Resolve central database connection configuration
        $connConfig = $company->databaseConnection;
        if (! $connConfig) {
            throw new TenantConnectionConfigException(
                'Configuración de conexión comercial no encontrada para la empresa.'
            );
        }

        $appUsername = (string) $connConfig->app_username;
        $appPassword = (string) $connConfig->app_password;

        if (empty($appUsername) || empty($appPassword) || empty($connConfig->database)) {
            throw new TenantConnectionConfigException(
                'Configuración o credenciales de aplicación incompletas para la base comercial.'
            );
        }

        // 4. Discard any prior tenant connection
        $this->purgeTenantConnection();

        // 5. Configure the dynamic 'tenant' connection using strictly application credentials (never migration or provisioner)
        Config::set('database.connections.tenant', [
            'driver' => $connConfig->driver ?: 'pgsql',
            'host' => $connConfig->host,
            'port' => (int) $connConfig->port,
            'database' => $connConfig->database,
            'username' => $appUsername,
            'password' => $appPassword,
            'schema' => $connConfig->schema ?: 'public',
            'sslmode' => $connConfig->ssl_mode ?: 'prefer',
            'search_path' => $connConfig->schema ?: 'public',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ]);

        // 6. Test and open the connection
        try {
            DB::connection('tenant')->getPdo();
        } catch (Throwable $e) {
            $this->disconnect();

            throw new TenantConnectionFailedException(
                'No fue posible establecer conexión con la base de datos comercial. Por favor intente más tarde.',
                503,
                $e
            );
        }

        // 7. Verify physical tenant_identity inside the database
        try {
            $identity = DB::connection('tenant')->table('tenant_identity')->first();
        } catch (Throwable $e) {
            $this->disconnect();

            throw new TenantConnectionFailedException(
                'Error al consultar la identidad de la base de datos comercial.',
                503,
                $e
            );
        }

        if (! $identity || (int) $identity->company_id !== (int) $company->id) {
            $this->disconnect();

            throw new TenantIdentityMismatchException(
                'Inconsistencia crítica de identidad en la base de datos comercial asignada. La conexión ha sido revocada inmediatamente por seguridad.'
            );
        }

        // 8. Enable commercial execution only after cryptographic & structural identity verification
        $this->activeCompany = $company;
        $this->activeTenantIdentity = $identity;
    }

    /**
     * Release the commercial database connection, purge cached state, and blank out sensitive credentials.
     */
    public function disconnect(): void
    {
        $this->purgeTenantConnection();

        // Overwrite sensitive credentials with empty values to avoid residual in-memory leakage
        Config::set('database.connections.tenant', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => '',
            'username' => '',
            'password' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'search_path' => 'public',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ]);

        $this->activeCompany = null;
        $this->activeTenantIdentity = null;
    }

    /**
     * Purge and disconnect the 'tenant' connection instance in Laravel's database manager.
     */
    protected function purgeTenantConnection(): void
    {
        try {
            DB::disconnect('tenant');
        } catch (Throwable) {
            // Ignore disconnect errors during teardown
        }

        try {
            DB::purge('tenant');
        } catch (Throwable) {
            // Ignore purge errors during teardown
        }
    }

    /**
     * Get the active company currently connected in tenant context.
     */
    public function getActiveCompany(): ?Company
    {
        return $this->activeCompany;
    }

    /**
     * Get the verified tenant_identity row of the active commercial database.
     */
    public function getActiveTenantIdentity(): ?object
    {
        return $this->activeTenantIdentity;
    }

    /**
     * Determine whether an active and verified commercial tenant context exists.
     */
    public function hasActiveTenant(): bool
    {
        return $this->activeCompany !== null;
    }

    /**
     * Assert that a commercial tenant context is active, throwing otherwise.
     *
     * @throws TenantContextException
     */
    public function assertActiveTenantContext(): void
    {
        if (! $this->hasActiveTenant()) {
            throw new TenantContextException(
                'Operación comercial no permitida: no hay un contexto empresarial activo para ejecutar consultas comerciales.'
            );
        }
    }

    /**
     * Determine whether the 'tenant' connection is actively open with a live PDO instance.
     */
    public function isConnectionOpen(): bool
    {
        if (! $this->hasActiveTenant()) {
            return false;
        }

        $connections = DB::getConnections();
        if (! array_key_exists('tenant', $connections)) {
            return false;
        }

        try {
            DB::connection('tenant')->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Execute a callback within an isolated commercial tenant context with guaranteed cleanup.
     * Reusable interface for background jobs, queues, and artisan commands.
     *
     * @template TReturn
     *
     * @param  callable(Company, ?object): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public function runInTenantContext(Company $company, callable $callback): mixed
    {
        $this->connect($company);

        try {
            return $callback($company, $this->activeTenantIdentity);
        } finally {
            $this->disconnect();
        }
    }
}
