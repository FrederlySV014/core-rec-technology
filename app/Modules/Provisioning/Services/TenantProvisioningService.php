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
    /**
     * Dedicated PDO session holding the exclusive operation-level advisory lock.
     */
    protected ?PDO $operationLockPdo = null;

    /**
     * Provision an independent commercial database for a company.
     *
     * @param  Company  $company  The company to provision.
     * @param  User|null  $actor  The user initiating the provisioning.
     * @param  bool  $isRetry  Whether this execution is an explicit retry.
     * @param  bool  $force  Whether to force recovery of a stalled in-progress execution.
     * @return CompanyDatabaseConnection The prepared and verified connection.
     *
     * @throws ProvisioningException
     */
    public function provision(
        Company $company,
        ?User $actor = null,
        bool $isRetry = false,
        bool $force = false
    ): CompanyDatabaseConnection {
        // 1. Acquire exclusive operation-level advisory lock across PostgreSQL cluster
        // (Guarantees zero overlap across processes, even under --force or stale timeout)
        $this->acquireOperationLock($company);

        try {
            // 2. Atomic pre-condition verification and pessimistic row lock acquisition
            $this->acquireProvisioningLock($company, $isRetry, $force);
        } catch (Throwable $e) {
            $this->releaseOperationLock($company);

            throw $e;
        }

        $startedAt = now();
        $stage = ProvisioningStage::CreateDatabase;

        try {
            $this->audit($company, $actor, 'tenant.provisioning_started', [
                'is_retry' => $isRetry,
                'company_id' => $company->id,
                'forced' => $force,
            ]);

            $details = [];

            // Derive stable technical names
            $dbName = $this->deriveDatabaseName($company);
            $migUsername = $this->deriveMigrationUsername($company);
            $appUsername = $this->deriveAppUsername($company);

            $this->assertValidIdentifier($dbName);
            $this->assertValidIdentifier($migUsername);
            $this->assertValidIdentifier($appUsername);

            $host = (string) config('tenancy.provisioner.host', '127.0.0.1');
            $port = (int) config('tenancy.provisioner.port', 5432);

            // Resolve or pre-persist connection credentials
            // (Guarantees consistency across aborted runs and avoids unnecessary password rotations)
            $existingConn = $company->databaseConnection;
            if ($existingConn) {
                $migPassword = $existingConn->migration_password;
                $appPassword = $existingConn->app_password;
            } else {
                $migPassword = Str::random(32);
                $appPassword = Str::random(32);

                $existingConn = CompanyDatabaseConnection::create([
                    'company_id' => $company->id,
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
                ]);
            }

            // STAGE 1: Create Database & Technical Users in PostgreSQL with Provenance Token
            $stage = ProvisioningStage::CreateDatabase;
            $stageStart = now();
            $creationToken = Str::uuid()->toString();

            $this->createDatabaseAndRoles(
                company: $company,
                dbName: $dbName,
                migUsername: $migUsername,
                migPassword: $migPassword,
                appUsername: $appUsername,
                appPassword: $appPassword,
                isRetry: $isRetry,
                creationToken: $creationToken
            );

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'database' => $dbName,
                'mig_user' => $migUsername,
                'app_user' => $appUsername,
                'provenance_token' => $creationToken,
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

            $this->seedTenantIdentity('tenant_migration', $company, $dbName, $appUsername);

            $this->recordLog($company, $stage, ProvisioningStatus::Ready, [
                'verified_company_id' => $company->id,
            ], null, $actor, $stageStart);

            // STAGE 4: Health Check (Verify App connection, identity immutability & DDL restriction)
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
                'identity_immutability_check' => 'passed',
                'ddl_restriction_check' => 'passed',
            ], null, $actor, $stageStart);

            // Ensure CompanyDatabaseConnection is fully synchronized
            $existingConn->update([
                'host' => $host,
                'port' => $port,
                'database' => $dbName,
                'app_username' => $appUsername,
                'app_password' => $appPassword,
                'migration_username' => $migUsername,
                'migration_password' => $migPassword,
            ]);

            // Mark company as Ready
            $this->markStatus($company, ProvisioningStatus::Ready);

            $this->audit($company, $actor, 'tenant.provisioning_completed', [
                'database' => $dbName,
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);

            return $existingConn;
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
            $this->releaseOperationLock($company);
        }
    }

    /**
     * Retry a failed or incomplete provisioning process.
     */
    public function retry(Company $company, ?User $actor = null, bool $force = false): CompanyDatabaseConnection
    {
        return $this->provision($company, $actor, isRetry: true, force: $force);
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
     * Atomically acquire the provisioning lock on the company using pessimistic row locking.
     *
     * @throws ProvisioningException
     */
    protected function acquireProvisioningLock(Company $company, bool $isRetry, bool $force = false): Company
    {
        return DB::connection('central')->transaction(function () use ($company, $isRetry, $force) {
            /** @var Company|null $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->first();

            if (! $lockedCompany) {
                throw new ProvisioningException("Empresa [{$company->id}] no encontrada para bloqueo de aprovisionamiento.");
            }

            $this->ensureCanBeProvisioned($lockedCompany, $isRetry, $force);

            $lockedCompany->update([
                'technical_provisioning_status' => ProvisioningStatus::InProgress,
            ]);

            $company->refresh();

            return $lockedCompany;
        });
    }

    /**
     * Check if company can be provisioned and evaluate abandoned execution timeouts.
     */
    protected function ensureCanBeProvisioned(Company $company, bool $isRetry, bool $force = false): void
    {
        if ($company->trashed() || $company->administrative_status === CompanyStatus::Cancelled) {
            throw new ProvisioningException('No se puede aprovisionar una empresa inactiva o cancelada.');
        }

        if ($company->technical_provisioning_status === ProvisioningStatus::Ready && ! $isRetry) {
            throw new ProvisioningException('La empresa ya cuenta con una base comercial preparada y lista.');
        }

        if ($company->technical_provisioning_status === ProvisioningStatus::InProgress) {
            $staleMinutes = (int) config('tenancy.stale_timeout_minutes', 15);
            $latestLog = $company->provisioningLogs()->latest('id')->first();
            $startTime = $latestLog !== null ? $latestLog->started_at : $company->updated_at;
            $elapsedMinutes = $startTime ? (int) abs(now()->diffInMinutes($startTime)) : 0;

            $isStale = $elapsedMinutes >= $staleMinutes;

            if (! $isStale && ! $force) {
                throw new ProvisioningException("Ya existe un proceso de aprovisionamiento en curso para esta empresa (iniciado hace {$elapsedMinutes} min). No se permite una segunda ejecución mientras la primera siga activa.");
            }

            if (! $isRetry && ! $force) {
                throw new ProvisioningException("El aprovisionamiento previo quedó en estado 'en curso' pero parece abandonado (iniciado hace {$elapsedMinutes} min). Para retomarlo de forma segura utilice la opción de reintento (--retry) o recuperación forzada.");
            }

            $this->recordLog(
                $company,
                ProvisioningStage::CreateDatabase,
                ProvisioningStatus::Failed,
                [
                    'recovery' => 'abandoned_execution_recovery',
                    'elapsed_minutes' => $elapsedMinutes,
                    'forced' => $force,
                ],
                $force ? 'Recuperación forzada por operador' : "Recuperación de ejecución abandonada por tiempo de espera ({$elapsedMinutes} min transcurridos)"
            );
        }
    }

    /**
     * Create PostgreSQL database and roles with least-privilege boundary.
     * Enforces Database Engine Discretionary Access Control (DAC) via REVOKE CONNECT FROM PUBLIC.
     */
    protected function createDatabaseAndRoles(
        Company $company,
        string $dbName,
        string $migUsername,
        string $migPassword,
        string $appUsername,
        string $appPassword,
        bool $isRetry,
        ?string $creationToken = null
    ): void {
        // Enforce DAC connection isolation on the project-managed central database
        $this->restrictCentralDatabasePublicAccess();

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

            // Stamp immutable creation provenance token in PostgreSQL catalog
            if ($creationToken) {
                $pdo->exec(sprintf(
                    'COMMENT ON DATABASE "%s" IS %s',
                    $dbName,
                    $pdo->quote("rec_provenance:company_{$company->id}:{$creationToken}")
                ));
            }
        }

        // 2. Discretionary Access Control (DAC): Revoke public connect privilege on this tenant database
        // (Ensures other database roles in PostgreSQL cannot connect to this tenant database)
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

        // Revoke any default privileges on all tables/sequences so non-ERP tables are never exposed to the app role
        $dbPdo->exec(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE "%s" IN SCHEMA public REVOKE ALL ON TABLES FROM "%s"', $migUsername, $appUsername));
        $dbPdo->exec(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE "%s" IN SCHEMA public REVOKE ALL ON SEQUENCES FROM "%s"', $migUsername, $appUsername));

        // Grant privileges ONLY on ERP-managed tables and sequences (categories, units_of_measure, products)
        $this->grantErpPrivileges($dbPdo, $appUsername);
    }

    /**
     * Verify an existing database belongs to the company, or throw if mismatched/unknown.
     * Uses concrete PostgreSQL catalog provenance to recognize interrupted creations without adopting unknown databases.
     */
    protected function verifyExistingDatabaseBelongsToCompany(Company $company, string $dbName, bool $isRetry): void
    {
        $dbPdo = $this->getDatabaseAdminPdo($dbName);

        // 1. Check if tenant_identity table exists
        $stmt = $dbPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'tenant_identity'");
        $identityTableExists = $stmt && (bool) $stmt->fetchColumn();

        if ($identityTableExists) {
            $stmt = $dbPdo->query('SELECT company_id FROM tenant_identity LIMIT 1');
            $existingId = $stmt ? $stmt->fetchColumn() : false;

            if ($existingId !== false && (int) $existingId !== (int) $company->id) {
                throw new ProvisioningException("La base de datos [{$dbName}] ya existe pero pertenece a otra empresa (ID: {$existingId}). No se adoptará ni se eliminará.");
            }

            return;
        }

        // 2. Early failure scenario: tenant_identity does NOT exist yet.
        // Inspect concrete database-level provenance token in PostgreSQL catalog
        $adminPdo = $this->getAdminPdo();
        $stmtDesc = $adminPdo->prepare("SELECT pg_catalog.shobj_description(d.oid, 'pg_database') FROM pg_catalog.pg_database d WHERE d.datname = :dbname");
        $stmtDesc->execute(['dbname' => $dbName]);
        $databaseComment = (string) $stmtDesc->fetchColumn();

        $expectedPrefix = "rec_provenance:company_{$company->id}:";
        if (str_starts_with($databaseComment, $expectedPrefix)) {
            $token = substr($databaseComment, strlen($expectedPrefix));

            // Verify central evidence: was this token recorded in a successful CreateDatabase stage by our platform?
            $hasVerifiedCreationLog = ProvisioningLog::where('company_id', $company->id)
                ->where('stage', ProvisioningStage::CreateDatabase)
                ->where('status', ProvisioningStatus::Ready)
                ->whereJsonContains('details->provenance_token', $token)
                ->exists();

            if ($isRetry && $hasVerifiedCreationLog) {
                // Provenance concretely verified: this exact physical database was created by the platform
                return;
            }
        }

        // Check if another company's connection references this database
        $conflictingConnection = CompanyDatabaseConnection::where('database', $dbName)
            ->where('company_id', '!=', $company->id)
            ->first();

        if ($conflictingConnection) {
            throw new ProvisioningException("La base de datos [{$dbName}] ya está asignada a otra empresa registrada (ID: {$conflictingConnection->company_id}).");
        }

        // Alien database, or database without verifiable creation evidence:
        // Conserve the resource intact, do not adopt, do not drop, require manual reconciliation
        throw new ProvisioningException("La base de datos [{$dbName}] ya existe pero no cuenta con evidencia comprobable de procedencia (sin tenant_identity ni token de creación verificado). Por seguridad, se conserva el recurso intacto y se requiere conciliación explícita del administrador.");
    }

    /**
     * Acquire an operation-level PostgreSQL advisory lock covering the entire provisioning execution.
     * Guarantees zero concurrent or overlapping executions across workers and CLI, even with --force.
     *
     * @throws ProvisioningException
     */
    public function acquireOperationLock(Company $company): void
    {
        if ($this->operationLockPdo !== null) {
            return;
        }

        $centralConfig = DB::connection('central')->getConfig();
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $centralConfig['host'],
            $centralConfig['port'],
            $centralConfig['database']
        );

        $pdo = new PDO(
            $dsn,
            (string) $centralConfig['username'],
            (string) $centralConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $lockKey = $this->deriveAdvisoryLockKey($company);

        $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(:lock_key)');
        $stmt->execute(['lock_key' => $lockKey]);
        $acquired = (bool) $stmt->fetchColumn();

        if (! $acquired) {
            throw new ProvisioningException('Existe un proceso de aprovisionamiento activo en ejecución para esta empresa (bloqueo exclusivo de operación en PostgreSQL). No se permite una ejecución simultánea ni solapada, incluso con --force.');
        }

        $this->operationLockPdo = $pdo;
    }

    /**
     * Release the operation-level PostgreSQL advisory lock and close the session.
     */
    public function releaseOperationLock(Company $company): void
    {
        if ($this->operationLockPdo === null) {
            return;
        }

        try {
            $lockKey = $this->deriveAdvisoryLockKey($company);
            $stmt = $this->operationLockPdo->prepare('SELECT pg_advisory_unlock(:lock_key)');
            $stmt->execute(['lock_key' => $lockKey]);
        } catch (Throwable) {
            // Ignore unlock errors during teardown
        } finally {
            $this->operationLockPdo = null;
        }
    }

    /**
     * Derive a deterministic integer key for PostgreSQL advisory locking per company.
     */
    public function deriveAdvisoryLockKey(Company $company): int
    {
        return (int) sprintf('%u', crc32("rec_tenant_provisioning_{$company->id}"));
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
     * Seed and protect the internal tenant identity inside the commercial database.
     * Enforces that application credentials can only SELECT, never mutate or delete it.
     */
    protected function seedTenantIdentity(string $connectionName, Company $company, string $dbName, string $appUsername): void
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

        // Enforce ERP table privileges and identity immutability
        $dbPdo = $this->getDatabaseAdminPdo($dbName);
        $this->grantErpPrivileges($dbPdo, $appUsername);
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

        // 2. Identity Immutability check: App user CANNOT UPDATE tenant_identity
        try {
            $conn->table('tenant_identity')->where('company_id', $company->id)->update(['tax_id' => 'tamper_attempt']);
            throw new ProvisioningException('Violación de seguridad: El usuario de aplicación pudo modificar la identidad técnica (tenant_identity).');
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (! str_contains($msg, 'permission denied') && ! str_contains($msg, 'permiso denegado')) {
                throw $e;
            }
        }

        // 3. Identity Immutability check: App user CANNOT DELETE tenant_identity
        try {
            $conn->table('tenant_identity')->where('company_id', $company->id)->delete();
            throw new ProvisioningException('Violación de seguridad: El usuario de aplicación pudo eliminar la identidad técnica (tenant_identity).');
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (! str_contains($msg, 'permission denied') && ! str_contains($msg, 'permiso denegado')) {
                throw $e;
            }
        }

        // 4. DDL Restriction check (Least privilege enforcement: App user MUST NOT be able to create tables)
        try {
            $conn->statement('CREATE TABLE __probe_security_test (id INT)');
            $conn->statement('DROP TABLE IF EXISTS __probe_security_test');
            throw new ProvisioningException('Violación de seguridad: El usuario de aplicación posee privilegios DDL no autorizados.');
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (! str_contains($msg, 'permission denied') && ! str_contains($msg, 'permiso denegado')) {
                throw $e;
            }
        }
    }

    /**
     * Restrict discretionary access control (DAC) on the project's central database in PostgreSQL.
     * Revokes CONNECT from PUBLIC so that tenant application roles cannot access central data,
     * limiting database engine permissions strictly to the central application user and superusers.
     */
    public function restrictCentralDatabasePublicAccess(): void
    {
        $centralDb = (string) config('database.connections.central.database');
        $centralUser = (string) config('database.connections.central.username', 'postgres');

        if (empty($centralDb)) {
            return;
        }

        $this->assertValidIdentifier($centralDb);

        $adminPdo = $this->getAdminPdo();

        // Check if the central database exists on this server
        $stmt = $adminPdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $stmt->execute(['dbname' => $centralDb]);
        if (! $stmt->fetchColumn()) {
            return;
        }

        // Revoke CONNECT from PUBLIC on the project-managed central database (DAC enforcement)
        $adminPdo->exec(sprintf('REVOKE CONNECT ON DATABASE "%s" FROM PUBLIC', $centralDb));

        // Ensure the central application role retains CONNECT
        if (! empty($centralUser)) {
            $this->assertValidIdentifier($centralUser);
            $adminPdo->exec(sprintf('GRANT CONNECT ON DATABASE "%s" TO "%s"', $centralDb, $centralUser));
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
     * Grant privileges strictly limited to ERP-managed tables and sequences.
     * Prevents privilege leakage to non-ERP tables (e.g. stores, integrations) sharing the database.
     */
    public function grantErpPrivileges(PDO $dbPdo, string $appUsername): void
    {
        $erpTables = ['categories', 'units_of_measure', 'products'];

        foreach ($erpTables as $table) {
            $stmt = $dbPdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :tbl");
            $stmt->execute(['tbl' => $table]);
            if ($stmt->fetchColumn()) {
                $dbPdo->exec(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "%s" TO "%s"', $table, $appUsername));

                // Find sequences specifically bound to this ERP table
                $seqStmt = $dbPdo->prepare("
                    SELECT s.relname AS seqname
                    FROM pg_class s
                    JOIN pg_depend d ON d.objid = s.oid
                    JOIN pg_class t ON d.refobjid = t.oid
                    JOIN pg_namespace n ON n.oid = s.relnamespace
                    WHERE s.relkind = 'S' AND n.nspname = 'public' AND t.relname = :tbl
                ");
                $seqStmt->execute(['tbl' => $table]);
                $sequences = $seqStmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($sequences as $seq) {
                    $dbPdo->exec(sprintf('GRANT USAGE, SELECT, UPDATE ON SEQUENCE "%s" TO "%s"', $seq, $appUsername));
                }
            }
        }

        // Always re-enforce that tenant_identity table is strictly read-only for appUsername if it exists
        $stmtIdentity = $dbPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'tenant_identity'");
        if ($stmtIdentity && $stmtIdentity->fetchColumn()) {
            $dbPdo->exec(sprintf('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE tenant_identity FROM "%s"', $appUsername));
            $dbPdo->exec(sprintf('GRANT SELECT ON TABLE tenant_identity TO "%s"', $appUsername));
        }
    }

    /**
     * Get PDO connection to a specific tenant database as provisioner.
     */
    public function getDatabaseAdminPdo(string $dbName): PDO
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
