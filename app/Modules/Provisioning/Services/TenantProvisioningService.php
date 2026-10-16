<?php

namespace App\Modules\Provisioning\Services;

use App\Models\User;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Enums\ProvisioningStage;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Models\CompanyDatabaseConnection;
use App\Modules\Provisioning\Models\ProvisioningLog;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class TenantProvisioningService
{
    /**
     * Provision an independent commercial database for a company.
     *
     * @param  Company  $company  The company to provision.
     * @param  User|null  $actor  The user initiating the provisioning.
     * @param  bool  $isRetry  Whether this execution is an explicit retry.
     * @return CompanyDatabaseConnection The prepared and verified connection.
     *
     * @throws ProvisioningException
     */
    public function provision(Company $company, ?User $actor = null, bool $isRetry = false): CompanyDatabaseConnection
    {
        // 1. Pre-condition checks and concurrency lock
        $this->ensureCanBeProvisioned($company, $isRetry);

        $startedAt = now();
        $this->markStatus($company, ProvisioningStatus::InProgress);

        $this->audit($company, $actor, 'tenant.provisioning_started', [
            'is_retry' => $isRetry,
            'company_id' => $company->id,
        ]);

        $stage = ProvisioningStage::CreateDatabase;
        $details = [];

        try {
            // Derive stable technical names
            $dbName = $this->deriveDatabaseName($company);
            $migUsername = $this->deriveMigrationUsername($company);
            $appUsername = $this->deriveAppUsername($company);

            $this->assertValidIdentifier($dbName);
            $this->assertValidIdentifier($migUsername);
            $this->assertValidIdentifier($appUsername);

            // Resolve or generate passwords
            $existingConn = $company->databaseConnection;
            $migPassword = $existingConn?->migration_password ?: Str::random(32);
            $appPassword = $existingConn?->app_password ?: Str::random(32);

            $host = (string) config('tenancy.provisioner.host', '127.0.0.1');
            $port = (int) config('tenancy.provisioner.port', 5432);

            // STAGE 1: Create Database & Technical Users in PostgreSQL
            $stage = ProvisioningStage::CreateDatabase;
            $stageStart = now();

            $this->createDatabaseAndRoles(
                company: $company,
                dbName: $dbName,
                migUsername: $migUsername,
                migPassword: $migPassword,
                appUsername: $appUsername,
                appPassword: $appPassword,
                isRetry: $isRetry
            );

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'database' => $dbName,
                'mig_user' => $migUsername,
                'app_user' => $appUsername,
            ], null, $actor, $stageStart);

            // STAGE 2: Run Exclusively Commercial (Tenant) Migrations
            $stage = ProvisioningStage::RunMigrations;
            $stageStart = now();

            $this->configureDynamicConnection('tenant_migration', [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                'database' => $dbName,
                'username' => $migUsername,
                'password' => $migPassword,
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);

            $this->runTenantMigrations('tenant_migration');

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'migrations_path' => 'database/migrations/tenant',
            ], null, $actor, $stageStart);

            // STAGE 3: Seed and Verify Tenant Identity
            $stage = ProvisioningStage::SeedData;
            $stageStart = now();

            $this->seedTenantIdentity('tenant_migration', $company);

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'verified_company_id' => $company->id,
            ], null, $actor, $stageStart);

            // STAGE 4: Health Check (Verify App connection, identity & DDL restriction)
            $stage = ProvisioningStage::HealthCheck;
            $stageStart = now();

            $this->configureDynamicConnection('tenant_app', [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                'database' => $dbName,
                'username' => $appUsername,
                'password' => $appPassword,
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);

            $this->performHealthChecks('tenant_app', $company);

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'connection_check' => 'passed',
                'identity_check' => 'passed',
                'ddl_restriction_check' => 'passed',
            ], null, $actor, $stageStart);

            // Save or update CompanyDatabaseConnection record (passwords encrypted automatically)
            $connection = CompanyDatabaseConnection::updateOrCreate(
                ['company_id' => $company->id],
                [
                    'driver' => 'pgsql',
                    'host' => $host,
                    'port' => $port,
                    'database' => $dbName,
                    'schema' => 'public',
                    'app_username' => $appUsername,
                    'app_password' => $appPassword,
                    'migration_username' => $migUsername,
                    'migration_password' => $migPassword,
                    'ssl_mode' => 'prefer',
                    'is_external_host' => false,
                ]
            );

            // Mark company as Ready
            $this->markStatus($company, ProvisioningStatus::Ready);

            $this->audit($company, $actor, 'tenant.provisioning_completed', [
                'database' => $dbName,
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);

            return $connection;
        } catch (Throwable $e) {
            $sanitizedMessage = $this->sanitizeErrorMessage($e->getMessage());

            $this->recordLog($company, $stage, ProvisioningStatus::Failed, [
                'failed_at_stage' => $stage->value,
            ], $sanitizedMessage, $actor, $startedAt);

            $this->markStatus($company, ProvisioningStatus::Failed);

            $this->audit($company, $actor, 'tenant.provisioning_failed', [
                'failed_stage' => $stage->value,
                'error' => $sanitizedMessage,
            ]);

            throw ProvisioningException::sanitized(
                "Error durante el aprovisionamiento en la etapa [{$stage->value}]: {$sanitizedMessage}",
                $e
            );
        } finally {
            $this->cleanupDynamicConnections();
        }
    }

    /**
     * Retry a failed or incomplete provisioning process.
     */
    public function retry(Company $company, ?User $actor = null): CompanyDatabaseConnection
    {
        return $this->provision($company, $actor, isRetry: true);
    }

    /**
     * Inspect technical provisioning status without opening unnecessary commercial connections.
     *
     * @return array{
     *     status: string,
     *     is_ready: bool,
     *     database: string|null,
     *     host: string|null,
     *     port: int|null,
     *     app_username: string|null,
     *     last_log: array<string, mixed>|null,
     *     logs_count: int
     * }
     */
    public function getStatus(Company $company): array
    {
        $connection = $company->databaseConnection;
        $lastLog = $company->provisioningLogs()->latest('id')->first();

        return [
            'status' => $company->technical_provisioning_status->value,
            'is_ready' => $company->technical_provisioning_status === ProvisioningStatus::Ready,
            'database' => $connection?->database,
            'host' => $connection?->host,
            'port' => $connection?->port,
            'app_username' => $connection?->app_username,
            'last_log' => $lastLog ? [
                'stage' => $lastLog->stage->value,
                'status' => $lastLog->status->value,
                'started_at' => $lastLog->started_at?->toIso8601String(),
                'finished_at' => $lastLog->finished_at?->toIso8601String(),
                'error_message' => $lastLog->error_message,
            ] : null,
            'logs_count' => $company->provisioningLogs()->count(),
        ];
    }

    /**
     * Check if company can be provisioned.
     */
    protected function ensureCanBeProvisioned(Company $company, bool $isRetry): void
    {
        if ($company->trashed() || $company->administrative_status === CompanyStatus::Cancelled) {
            throw new ProvisioningException('No se puede aprovisionar una empresa inactiva o cancelada.');
        }

        if ($company->technical_provisioning_status === ProvisioningStatus::InProgress) {
            throw new ProvisioningException('Ya existe un proceso de aprovisionamiento en curso para esta empresa.');
        }

        if ($company->technical_provisioning_status === ProvisioningStatus::Ready && ! $isRetry) {
            // Already provisioned and not retrying
            throw new ProvisioningException('La empresa ya cuenta con una base comercial preparada y lista.');
        }
    }

    /**
     * Create PostgreSQL database and roles with least-privilege boundary.
     */
    protected function createDatabaseAndRoles(
        Company $company,
        string $dbName,
        string $migUsername,
        string $migPassword,
        string $appUsername,
        string $appPassword,
        bool $isRetry
    ): void {
        $pdo = $this->getAdminPdo();

        // 1. Check if database already exists
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $stmt->execute(['dbname' => $dbName]);
        $dbExists = (bool) $stmt->fetchColumn();

        if ($dbExists) {
            // Verify provenance: Does it belong to this company?
            $this->verifyExistingDatabaseBelongsToCompany($company, $dbName, $isRetry);
        } else {
            // In PostgreSQL, CREATE DATABASE cannot execute inside a transaction block.
            $pdo->exec(sprintf('CREATE DATABASE "%s" WITH ENCODING %s', $dbName, $pdo->quote('UTF8')));
        }

        // 2. Revoke public connect privilege on this database (prevent other tenants from connecting)
        $pdo->exec(sprintf('REVOKE CONNECT ON DATABASE "%s" FROM PUBLIC', $dbName));

        // 3. Create or update migration role (DDL privileges)
        $stmtRole = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = :rolename');
        $stmtRole->execute(['rolename' => $migUsername]);
        if (! $stmtRole->fetchColumn()) {
            $pdo->exec(sprintf('CREATE ROLE "%s" WITH LOGIN PASSWORD %s NOSUPERUSER NOCREATEDB NOCREATEROLE', $migUsername, $pdo->quote($migPassword)));
        } else {
            $pdo->exec(sprintf('ALTER ROLE "%s" WITH PASSWORD %s', $migUsername, $pdo->quote($migPassword)));
        }

        // 4. Create or update application role (DML only, no DDL)
        $stmtRole->execute(['rolename' => $appUsername]);
        if (! $stmtRole->fetchColumn()) {
            $pdo->exec(sprintf('CREATE ROLE "%s" WITH LOGIN PASSWORD %s NOSUPERUSER NOCREATEDB NOCREATEROLE', $appUsername, $pdo->quote($appPassword)));
        } else {
            $pdo->exec(sprintf('ALTER ROLE "%s" WITH PASSWORD %s', $appUsername, $pdo->quote($appPassword)));
        }

        // 5. Grant CONNECT on this database only to its dedicated users
        $pdo->exec(sprintf('GRANT CONNECT ON DATABASE "%s" TO "%s", "%s"', $dbName, $migUsername, $appUsername));

        // 6. Connect directly to the new database as provisioner to configure schema privileges
        $dbPdo = $this->getDatabaseAdminPdo($dbName);

        // Secure public schema
        $dbPdo->exec('REVOKE ALL ON SCHEMA public FROM PUBLIC');

        // Grant DDL rights to migration user
        $dbPdo->exec(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO "%s"', $migUsername));

        // Grant USAGE (lookup) rights to app user (no CREATE, no DDL!)
        $dbPdo->exec(sprintf('GRANT USAGE ON SCHEMA public TO "%s"', $appUsername));

        // Default privileges: any future table/sequence created by migUsername is readable/writable by appUsername
        $dbPdo->exec(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE "%s" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "%s"',
            $migUsername,
            $appUsername
        ));
        $dbPdo->exec(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE "%s" IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO "%s"',
            $migUsername,
            $appUsername
        ));

        // If tables already exist (retry scenario), grant permissions immediately
        $dbPdo->exec(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "%s"', $appUsername));
        $dbPdo->exec(sprintf('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO "%s"', $appUsername));
    }

    /**
     * Verify an existing database belongs to the company, or throw if mismatched.
     */
    protected function verifyExistingDatabaseBelongsToCompany(Company $company, string $dbName, bool $isRetry): void
    {
        $dbPdo = $this->getDatabaseAdminPdo($dbName);

        // Check if tenant_identity table exists
        $stmt = $dbPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'tenant_identity'");
        if (! $stmt || ! $stmt->fetchColumn()) {
            if ($isRetry) {
                // In retry, table might not have been created yet, allow continuing
                return;
            }

            throw new ProvisioningException("La base de datos [{$dbName}] ya existe pero no contiene identificación técnica válida.");
        }

        $stmt = $dbPdo->query('SELECT company_id FROM tenant_identity LIMIT 1');
        $existingId = $stmt ? $stmt->fetchColumn() : false;

        if ($existingId !== false && (int) $existingId !== (int) $company->id) {
            throw new ProvisioningException("La base de datos [{$dbName}] ya existe pero pertenece a otra empresa (ID: {$existingId}).");
        }
    }

    /**
     * Run tenant migrations exclusively.
     */
    protected function runTenantMigrations(string $connectionName): void
    {
        Artisan::call('migrate', [
            '--database' => $connectionName,
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    /**
     * Seed or verify the internal tenant identity inside the commercial database.
     */
    protected function seedTenantIdentity(string $connectionName, Company $company): void
    {
        $conn = DB::connection($connectionName);

        $conn->table('tenant_identity')->updateOrInsert(
            ['company_id' => $company->id],
            [
                'tax_id' => $company->tax_id,
                'schema_version' => '1.0.0',
                'provisioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Perform health checks on the newly provisioned commercial database.
     */
    protected function performHealthChecks(string $connectionName, Company $company): void
    {
        $conn = DB::connection($connectionName);

        // 1. Connection and Identity read check
        $row = $conn->table('tenant_identity')->where('company_id', $company->id)->first();
        if (! $row || (int) $row->company_id !== (int) $company->id) {
            throw new ProvisioningException('Fallo de verificación: No se pudo verificar la identidad empresarial en la base de datos comercial.');
        }

        // 2. DDL Restriction check (Least privilege enforcement: App user MUST NOT be able to create tables)
        try {
            $conn->statement('CREATE TABLE __probe_security_test (id INT)');
            // If it succeeds, drop it and throw security exception
            $conn->statement('DROP TABLE IF EXISTS __probe_security_test');
            throw new ProvisioningException('Violación de seguridad: El usuario de aplicación posee privilegios DDL no autorizados.');
        } catch (QueryException $e) {
            // Expected: permission denied for schema public
            $msg = strtolower($e->getMessage());
            if (! str_contains($msg, 'permission denied') && ! str_contains($msg, 'permiso denegado')) {
                throw $e;
            }
        }
    }

    /**
     * Drop a tenant database and its roles (strictly for test tearDown or controlled cleanup).
     */
    public function dropTenantDatabase(Company $company): void
    {
        $dbName = $this->deriveDatabaseName($company);
        $migUsername = $this->deriveMigrationUsername($company);
        $appUsername = $this->deriveAppUsername($company);

        $pdo = $this->getAdminPdo();

        // Terminate existing connections to the database
        $pdo->exec(sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()",
            $dbName
        ));

        // Drop database
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbName));

        // Drop roles
        $pdo->exec(sprintf('DROP ROLE IF EXISTS "%s"', $migUsername));
        $pdo->exec(sprintf('DROP ROLE IF EXISTS "%s"', $appUsername));

        // Clean central connection record
        $company->databaseConnection()->delete();
        $this->markStatus($company, ProvisioningStatus::Pending);
    }

    /**
     * Derive technical database name from stable company identifier.
     */
    public function deriveDatabaseName(Company $company): string
    {
        $prefix = (string) config('tenancy.provisioner.db_prefix', 'rec_tenant_');

        return "{$prefix}{$company->id}";
    }

    /**
     * Derive migration username from stable company identifier.
     */
    public function deriveMigrationUsername(Company $company): string
    {
        $prefix = (string) config('tenancy.provisioner.user_prefix', 'rec_');

        return "{$prefix}mig_{$company->id}";
    }

    /**
     * Derive application username from stable company identifier.
     */
    public function deriveAppUsername(Company $company): string
    {
        $prefix = (string) config('tenancy.provisioner.user_prefix', 'rec_');

        return "{$prefix}app_{$company->id}";
    }

    /**
     * Assert an identifier is safe for PostgreSQL DDL.
     */
    protected function assertValidIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]{1,63}$/', $identifier)) {
            throw new ProvisioningException("Identificador técnico de base de datos no válido: [{$identifier}]");
        }
    }

    /**
     * Configure a dynamic database connection in Laravel config.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function configureDynamicConnection(string $name, array $settings): void
    {
        Config::set("database.connections.{$name}", $settings);
        DB::purge($name);
    }

    /**
     * Clean up dynamic connections.
     */
    protected function cleanupDynamicConnections(): void
    {
        DB::purge('tenant_migration');
        DB::purge('tenant_app');
    }

    /**
     * Get PDO connection to maintenance database as provisioner.
     */
    protected function getAdminPdo(): PDO
    {
        $host = config('tenancy.provisioner.host', '127.0.0.1');
        $port = config('tenancy.provisioner.port', 5432);
        $database = config('tenancy.provisioner.database', 'postgres');
        $username = config('tenancy.provisioner.username', 'postgres');
        $password = config('tenancy.provisioner.password', '');

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        $pdo = new PDO($dsn, (string) $username, (string) $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }

    /**
     * Get PDO connection to a specific tenant database as provisioner.
     */
    protected function getDatabaseAdminPdo(string $dbName): PDO
    {
        $host = config('tenancy.provisioner.host', '127.0.0.1');
        $port = config('tenancy.provisioner.port', 5432);
        $username = config('tenancy.provisioner.username', 'postgres');
        $password = config('tenancy.provisioner.password', '');

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";

        return new PDO($dsn, (string) $username, (string) $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Mark company technical provisioning status.
     */
    protected function markStatus(Company $company, ProvisioningStatus $status): void
    {
        $company->update(['technical_provisioning_status' => $status]);
    }

    /**
     * Record a provisioning log entry.
     *
     * @param  array<string, mixed>|null  $details
     */
    protected function recordLog(
        Company $company,
        ProvisioningStage $stage,
        ProvisioningStatus $status,
        ?array $details = null,
        ?string $errorMessage = null,
        ?User $actor = null,
        ?CarbonInterface $startedAt = null
    ): void {
        ProvisioningLog::create([
            'company_id' => $company->id,
            'stage' => $stage,
            'status' => $status,
            'details' => $details,
            'error_message' => $errorMessage ? $this->sanitizeErrorMessage($errorMessage) : null,
            'executed_by_user_id' => $actor?->id,
            'started_at' => $startedAt ?? now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Audit provisioning action in central_audit_logs.
     *
     * @param  array<string, mixed>|null  $details
     */
    protected function audit(Company $company, ?User $actor, string $action, ?array $details = null): void
    {
        CentralAuditLog::create([
            'user_id' => $actor?->id,
            'company_id' => $company->id,
            'action' => $action,
            'auditable_type' => Company::class,
            'auditable_id' => (string) $company->id,
            'new_values' => $details,
        ]);
    }

    /**
     * Strip any plain text passwords or secrets from error strings.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $sanitized = preg_replace('/password(=|\s*:\s*|\s+)(\'[^\']*\'|"[^"]*"|\S+)/i', 'password=***', $message) ?? $message;

        return Str::limit($sanitized, 1000);
    }
}
