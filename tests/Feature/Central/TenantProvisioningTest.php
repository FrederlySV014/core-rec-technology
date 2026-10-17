<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Provisioning\Enums\ProvisioningStage;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Models\CompanyDatabaseConnection;
use App\Modules\Provisioning\Models\ProvisioningLog;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Tenant Commercial Database Provisioning (Phase 5)', function () {
    $cleanupCompanies = [];

    afterEach(function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        foreach ($cleanupCompanies as $company) {
            try {
                $service->dropTenantDatabase($company);
                $company->forceDelete();
            } catch (Throwable) {
                // Ignore cleanup errors during teardown
            }
        }
        $cleanupCompanies = [];
    });

    function createTestCompany(string $suffix, array &$cleanupList): Company
    {
        $unique = Str::lower(Str::random(6));
        $company = Company::create([
            'legal_name' => "Tenant Test {$suffix} {$unique} S.A.C.",
            'trade_name' => "Tenant Test {$suffix}",
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20'.rand(100000000, 999999999),
            'slug' => "test-tenant-{$suffix}-{$unique}",
            'contact_email' => "tenant.{$suffix}.{$unique}@example.com",
            'administrative_status' => CompanyStatus::Active,
            'technical_provisioning_status' => ProvisioningStatus::Pending,
        ]);

        $cleanupList[] = $company;

        return $company;
    }

    test('1. provision two companies with distinct databases and separate users', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);

        $companyA = createTestCompany('alpha', $cleanupCompanies);
        $companyB = createTestCompany('beta', $cleanupCompanies);

        $connA = $service->provision($companyA);
        $connB = $service->provision($companyB);

        expect($connA->database)->not->toBe($connB->database)
            ->and($connA->app_username)->not->toBe($connB->app_username)
            ->and($connA->migration_username)->not->toBe($connB->migration_username)
            ->and($companyA->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready)
            ->and($companyB->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready);
    });

    test('2. correspondence between central company and internal database identity', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('identity', $cleanupCompanies);

        $conn = $service->provision($company);

        Config::set('database.connections.test_tenant_verify', [
            'driver' => 'pgsql',
            'host' => $conn->host,
            'port' => $conn->port,
            'database' => $conn->database,
            'username' => $conn->app_username,
            'password' => $conn->app_password,
            'schema' => 'public',
        ]);
        DB::purge('test_tenant_verify');

        $identity = DB::connection('test_tenant_verify')->table('tenant_identity')->first();

        expect($identity)->not->toBeNull()
            ->and((int) $identity->company_id)->toBe((int) $company->id)
            ->and($identity->tax_id)->toBe($company->tax_id)
            ->and($identity->schema_version)->toBe('1.0.0');

        DB::purge('test_tenant_verify');
    });

    test('3. exclusive execution of commercial tenant migrations without central tables', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('migrations', $cleanupCompanies);

        $conn = $service->provision($company);

        Config::set('database.connections.test_tenant_tables', [
            'driver' => 'pgsql',
            'host' => $conn->host,
            'port' => $conn->port,
            'database' => $conn->database,
            'username' => $conn->app_username,
            'password' => $conn->app_password,
            'schema' => 'public',
        ]);
        DB::purge('test_tenant_tables');

        $tables = DB::connection('test_tenant_tables')
            ->select("SELECT tablename AS table_name FROM pg_tables WHERE schemaname = 'public'");

        $tableNames = array_map(fn ($row) => $row->table_name, $tables);

        // Commercial table exists
        expect($tableNames)->toContain('tenant_identity')
            ->and($tableNames)->toContain('migrations');

        // Central tables MUST NOT exist in commercial database
        expect($tableNames)->not->toContain('users')
            ->and($tableNames)->not->toContain('companies')
            ->and($tableNames)->not->toContain('licenses')
            ->and($tableNames)->not->toContain('memberships');

        DB::purge('test_tenant_tables');
    });

    test('4. successful connection with least-privileged application credentials', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('app-connect', $cleanupCompanies);

        $conn = $service->provision($company);

        Config::set('database.connections.test_app_runtime', [
            'driver' => 'pgsql',
            'host' => $conn->host,
            'port' => $conn->port,
            'database' => $conn->database,
            'username' => $conn->app_username,
            'password' => $conn->app_password,
            'schema' => 'public',
        ]);
        DB::purge('test_app_runtime');

        // Querying data via app user succeeds
        $count = DB::connection('test_app_runtime')->table('tenant_identity')->count();
        expect($count)->toBe(1);

        DB::purge('test_app_runtime');
    });

    test('5. PostgreSQL Discretionary Access Control (DAC) prevents DDL, cross-tenant database access, and central database access', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $companyA = createTestCompany('iso-a', $cleanupCompanies);
        $companyB = createTestCompany('iso-b', $cleanupCompanies);

        $connA = $service->provision($companyA);
        $connB = $service->provision($companyB);

        // Part 1: App user cannot execute DDL in its own database
        Config::set('database.connections.test_conn_a', [
            'driver' => 'pgsql',
            'host' => $connA->host,
            'port' => $connA->port,
            'database' => $connA->database,
            'username' => $connA->app_username,
            'password' => $connA->app_password,
            'schema' => 'public',
        ]);
        DB::purge('test_conn_a');

        $ddlFailed = false;
        try {
            DB::connection('test_conn_a')->statement('CREATE TABLE test_unauthorized_tbl (id int)');
        } catch (QueryException $e) {
            $ddlFailed = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                         str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }

        expect($ddlFailed)->toBeTrue();
        DB::purge('test_conn_a');

        // Part 2: Cross-database access: App User A cannot connect to Company B database
        $crossAccessDenied = false;
        try {
            $dsn = "pgsql:host={$connB->host};port={$connB->port};dbname={$connB->database}";
            new PDO($dsn, $connA->app_username, $connA->app_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $crossAccessDenied = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                                 str_contains(strtolower($e->getMessage()), 'fatal') ||
                                 str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }

        expect($crossAccessDenied)->toBeTrue();

        // Part 3: Central database access: App User A cannot connect to Central database
        $centralDb = (string) config('database.connections.central.database');
        $centralAccessDenied = false;
        try {
            $dsnCentral = "pgsql:host={$connA->host};port={$connA->port};dbname={$centralDb}";
            new PDO($dsnCentral, $connA->app_username, $connA->app_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $centralAccessDenied = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                                   str_contains(strtolower($e->getMessage()), 'fatal') ||
                                   str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }

        expect($centralAccessDenied)->toBeTrue();
    });

    test('6. repetition of completed provisioning without duplicating resources', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('repeat', $cleanupCompanies);

        $conn1 = $service->provision($company);

        // Normal provision on a Ready company should reject without --retry
        expect(fn () => $service->provision($company))
            ->toThrow(ProvisioningException::class, 'La empresa ya cuenta con una base comercial');

        // Retrying an already Ready company should be idempotent
        $conn2 = $service->retry($company);

        expect($conn2->id)->toBe($conn1->id)
            ->and($conn2->database)->toBe($conn1->database)
            ->and($company->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready)
            ->and(CompanyDatabaseConnection::where('company_id', $company->id)->count())->toBe(1);
    });

    test('7. recovery from partial failure via retry', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('recover', $cleanupCompanies);

        // Manually mark status as Failed to simulate past failure
        $company->update(['technical_provisioning_status' => ProvisioningStatus::Failed]);

        // Retrying must recover and leave the company Ready
        $conn = $service->retry($company);

        expect($conn)->toBeInstanceOf(CompanyDatabaseConnection::class)
            ->and($company->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready);
    });

    test('8. atomic provisioning lock acquisition prevents simultaneous concurrent attempts', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('atomic-race', $cleanupCompanies);

        Config::set('database.connections.test_central_secondary', config('database.connections.central'));
        DB::purge('test_central_secondary');

        // Commit so the test company is permanently committed in PostgreSQL and visible to independent connections
        if (DB::connection('central')->transactionLevel() > 0) {
            DB::connection('central')->commit();
        }

        // Step 1: Connection 1 begins transaction and acquires exclusive pessimistic lock on the company row
        DB::connection('central')->beginTransaction();
        $locked1 = Company::where('id', $company->id)->lockForUpdate()->first();
        expect($locked1)->not->toBeNull();

        // Step 2: Connection 2 (simulating a concurrent worker with independent PDO connection)
        $centralConfig = DB::connection('central')->getConfig();
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $centralConfig['host'],
            $centralConfig['port'],
            $centralConfig['database']
        );
        $pdo2 = new PDO(
            $dsn,
            (string) $centralConfig['username'],
            (string) $centralConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $lockContended = false;
        try {
            $pdo2->beginTransaction();
            $pdo2->query("SELECT id FROM companies WHERE id = {$company->id} FOR UPDATE NOWAIT");
        } catch (Throwable $e) {
            $lockContended = true;
        } finally {
            try {
                if ($pdo2->inTransaction()) {
                    $pdo2->rollBack();
                }
            } catch (Throwable) {
            }
        }

        expect($lockContended)->toBeTrue();

        // Step 3: Connection 1 transitions status to InProgress and commits
        $locked1->update(['technical_provisioning_status' => ProvisioningStatus::InProgress]);
        DB::connection('central')->commit();

        // Step 4: Connection 2 now attempts to provision and is atomically rejected
        expect(fn () => $service->provision($company))
            ->toThrow(ProvisioningException::class, 'Ya existe un proceso de aprovisionamiento en curso');

        DB::purge('test_central_secondary');
    });

    test('9. alien database without tenant_identity is rejected across initial attempt and retry, remaining intact', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('alien-prov', $cleanupCompanies);
        $dbName = $service->deriveDatabaseName($company);

        $adminPdoMethod = new ReflectionMethod($service, 'getAdminPdo');
        $adminPdoMethod->setAccessible(true);
        /** @var PDO $pdo */
        $pdo = $adminPdoMethod->invoke($service);

        // Pre-create alien database in PostgreSQL without provenance comment and without tenant_identity
        $pdo->exec(sprintf("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()", $dbName));
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbName));
        $pdo->exec(sprintf('CREATE DATABASE "%s" WITH ENCODING %s', $dbName, $pdo->quote('UTF8')));

        // Populate foreign payload inside the alien database to verify it remains 100% intact
        $dbAdminMethod = new ReflectionMethod($service, 'getDatabaseAdminPdo');
        $dbAdminMethod->setAccessible(true);
        /** @var PDO $dbPdo */
        $dbPdo = $dbAdminMethod->invoke($service, $dbName);
        $dbPdo->exec('CREATE TABLE alien_foreign_data (id serial primary key, note text)');
        $dbPdo->exec("INSERT INTO alien_foreign_data (note) VALUES ('unrelated third party payload')");

        // 1. Initial provisioning attempt: MUST reject the alien database and record failure
        $firstAttemptFailed = false;
        try {
            $service->provision($company);
        } catch (ProvisioningException $e) {
            $firstAttemptFailed = true;
            expect($e->getMessage())->toContain('no cuenta con evidencia comprobable de procedencia')
                ->and($e->getMessage())->toContain('conciliación explícita');
        }
        expect($firstAttemptFailed)->toBeTrue();

        // Verify state after first attempt:
        // - Company marked Failed
        // - ProvisioningLog recorded with Failed status
        // - Connection configuration was saved/pre-persisted
        expect($company->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Failed);
        $failedLog = ProvisioningLog::where('company_id', $company->id)
            ->where('stage', ProvisioningStage::CreateDatabase)
            ->where('status', ProvisioningStatus::Failed)
            ->first();
        expect($failedLog)->not->toBeNull();
        expect($company->fresh()->databaseConnection)->not->toBeNull();

        // 2. Second provisioning attempt with --retry:
        // Even though a provisioning_log exists and connection credentials were saved,
        // it MUST STILL BE REJECTED because attempts != concrete creation evidence.
        $secondAttemptFailed = false;
        try {
            $service->retry($company);
        } catch (ProvisioningException $e) {
            $secondAttemptFailed = true;
            expect($e->getMessage())->toContain('no cuenta con evidencia comprobable de procedencia')
                ->and($e->getMessage())->toContain('conciliación explícita');
        }
        expect($secondAttemptFailed)->toBeTrue();

        // 3. Verify alien database remained completely intact (not deleted, data unmodified)
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $stmt->execute(['dbname' => $dbName]);
        expect((bool) $stmt->fetchColumn())->toBeTrue();

        $stmtData = $dbPdo->query('SELECT note FROM alien_foreign_data LIMIT 1');
        expect($stmtData->fetchColumn())->toBe('unrelated third party payload');

        // Cleanup alien database
        $pdo->exec(sprintf("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()", $dbName));
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbName));

        // 4. Contrast with legitimate platform-created resource early failure:
        // Verifiable creation evidence (token in PostgreSQL catalog matching central Ready log)
        $companySafe = createTestCompany('proven-safe', $cleanupCompanies);
        $dbNameSafe = $service->deriveDatabaseName($companySafe);

        $pdo->exec(sprintf("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()", $dbNameSafe));
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $dbNameSafe));
        $pdo->exec(sprintf('CREATE DATABASE "%s" WITH ENCODING %s', $dbNameSafe, $pdo->quote('UTF8')));

        $safeToken = Str::uuid()->toString();
        $pdo->exec(sprintf(
            'COMMENT ON DATABASE "%s" IS %s',
            $dbNameSafe,
            $pdo->quote("rec_provenance:company_{$companySafe->id}:{$safeToken}")
        ));

        ProvisioningLog::create([
            'company_id' => $companySafe->id,
            'stage' => ProvisioningStage::CreateDatabase,
            'status' => ProvisioningStatus::Ready,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(3),
            'details' => ['provenance_token' => $safeToken],
        ]);

        // Retrying now safely recognizes the platform-created resource and completes provisioning
        $connSafe = $service->retry($companySafe);
        expect($connSafe)->toBeInstanceOf(CompanyDatabaseConnection::class)
            ->and($companySafe->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready)
            ->and($connSafe->database)->toBe($dbNameSafe);
    });

    test('10. whole-operation exclusion blocks concurrent retry and force while process is active, permitting recovery only after termination', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('excl-proc', $cleanupCompanies);

        $centralConfig = DB::connection('central')->getConfig();
        $lockKey = $service->deriveAdvisoryLockKey($company);

        // Spawn Process 1 as an independent OS process that acquires the PostgreSQL advisory lock
        $phpCode = sprintf(
            '$pdo = new PDO("pgsql:host=%s;port=%d;dbname=%s", "%s", "%s", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); '.
            '$stmt = $pdo->prepare("SELECT pg_try_advisory_lock(%d)"); '.
            '$stmt->execute(); '.
            '$locked = (bool) $stmt->fetchColumn(); '.
            'if ($locked) { echo "LOCKED\n"; flush(); } else { echo "LOCK_FAILED\n"; flush(); } '.
            'sleep(30);',
            $centralConfig['host'],
            $centralConfig['port'],
            $centralConfig['database'],
            $centralConfig['username'],
            $centralConfig['password'],
            $lockKey
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc1 = proc_open([PHP_BINARY, '-r', $phpCode], $descriptors, $pipes);
        expect(is_resource($proc1))->toBeTrue();

        // Wait until Process 1 confirms it acquired the advisory lock
        $statusLine = trim(fgets($pipes[1]));
        expect($statusLine)->toBe('LOCKED');

        // Simulate that Process 1 has been in progress beyond the stale threshold (e.g. 25 minutes ago > 15 min threshold)
        $company->update(['technical_provisioning_status' => ProvisioningStatus::InProgress]);
        ProvisioningLog::create([
            'company_id' => $company->id,
            'stage' => ProvisioningStage::CreateDatabase,
            'status' => ProvisioningStatus::InProgress,
            'started_at' => now()->subMinutes(25),
        ]);

        // Process 2 (the current test process) attempts to recover:
        // 1. Attempt recovery via --retry: MUST be blocked because Process 1 is still active
        expect(fn () => $service->retry($company))
            ->toThrow(ProvisioningException::class, 'bloqueo exclusivo de operación en PostgreSQL');

        // 2. Attempt recovery via --force: MUST ALSO be blocked because --force cannot overlap with an active process
        expect(fn () => $service->retry($company, force: true))
            ->toThrow(ProvisioningException::class, 'bloqueo exclusivo de operación en PostgreSQL');

        // Neither --retry nor --force could overlap with Process 1 while it was active.
        // 3. Now, terminate/interrupt Process 1 (simulating crash or worker termination)
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc1);
        proc_close($proc1);

        // Small pause to allow OS socket cleanup
        usleep(100000); // 100ms

        // 4. Tras terminar o interrumpir realmente el primer proceso, la recuperación sí es posible
        $recoveredConn = $service->retry($company, force: true);

        expect($recoveredConn)->toBeInstanceOf(CompanyDatabaseConnection::class)
            ->and($company->fresh()->technical_provisioning_status)->toBe(ProvisioningStatus::Ready);
    });

    test('11. consistent role credentials prevent unnecessary rotations and heal desynchronized PostgreSQL passwords', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('creds-sync', $cleanupCompanies);

        $conn1 = $service->provision($company);
        $origAppPass = $conn1->app_password;
        $origMigPass = $conn1->migration_password;

        // Retry should retain identical passwords (no unnecessary rotation)
        $conn2 = $service->retry($company);
        expect($conn2->app_password)->toBe($origAppPass)
            ->and($conn2->migration_password)->toBe($origMigPass);

        // Simulate external out-of-band password desynchronization in PostgreSQL
        $adminPdoMethod = new ReflectionMethod($service, 'getAdminPdo');
        $adminPdoMethod->setAccessible(true);
        /** @var PDO $pdo */
        $pdo = $adminPdoMethod->invoke($service);

        $pdo->exec(sprintf('ALTER ROLE "%s" WITH PASSWORD %s', $conn1->app_username, $pdo->quote('desynchronized_secret_999')));

        // Attempting to connect with central password now fails due to desync
        $desyncFailed = false;
        try {
            $dsn = "pgsql:host={$conn1->host};port={$conn1->port};dbname={$conn1->database}";
            new PDO($dsn, $conn1->app_username, $conn1->app_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $desyncFailed = true;
        }
        expect($desyncFailed)->toBeTrue();

        // Retrying the provisioning automatically heals the PostgreSQL role to match central credentials
        $conn3 = $service->retry($company);

        expect($conn3->app_password)->toBe($origAppPass);

        // Connection now succeeds with central password
        $dsn = "pgsql:host={$conn3->host};port={$conn3->port};dbname={$conn3->database}";
        $healedPdo = new PDO($dsn, $conn3->app_username, $conn3->app_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        expect($healedPdo)->toBeInstanceOf(PDO::class);
    });

    test('12. application credentials can query tenant_identity but cannot modify, delete or truncate it', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('identity-immut', $cleanupCompanies);

        $conn = $service->provision($company);

        Config::set('database.connections.test_app_immut', [
            'driver' => 'pgsql',
            'host' => $conn->host,
            'port' => $conn->port,
            'database' => $conn->database,
            'username' => $conn->app_username,
            'password' => $conn->app_password,
            'schema' => 'public',
        ]);
        DB::purge('test_app_immut');

        // 1. SELECT succeeds
        $identity = DB::connection('test_app_immut')->table('tenant_identity')->first();
        expect($identity)->not->toBeNull()
            ->and((int) $identity->company_id)->toBe((int) $company->id);

        // 2. UPDATE fails with permission denied
        $updateFailed = false;
        try {
            DB::connection('test_app_immut')->table('tenant_identity')
                ->where('company_id', $company->id)
                ->update(['tax_id' => 'modified_tax_id']);
        } catch (QueryException $e) {
            $updateFailed = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                            str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }
        expect($updateFailed)->toBeTrue();

        // 3. DELETE fails with permission denied
        $deleteFailed = false;
        try {
            DB::connection('test_app_immut')->table('tenant_identity')
                ->where('company_id', $company->id)
                ->delete();
        } catch (QueryException $e) {
            $deleteFailed = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                            str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }
        expect($deleteFailed)->toBeTrue();

        // 4. TRUNCATE fails with permission denied
        $truncateFailed = false;
        try {
            DB::connection('test_app_immut')->statement('TRUNCATE TABLE tenant_identity');
        } catch (QueryException $e) {
            $truncateFailed = str_contains(strtolower($e->getMessage()), 'permission denied') ||
                              str_contains(strtolower($e->getMessage()), 'permiso denegado');
        }
        expect($truncateFailed)->toBeTrue();

        DB::purge('test_app_immut');
    });

    test('13. rejection of existing database with mismatched identity', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $companyA = createTestCompany('mismatch-a', $cleanupCompanies);
        $companyB = createTestCompany('mismatch-b', $cleanupCompanies);

        // Provision Company A
        $service->provision($companyA);

        $dbNameA = $service->deriveDatabaseName($companyA);

        $method = new ReflectionMethod($service, 'verifyExistingDatabaseBelongsToCompany');
        $method->setAccessible(true);

        expect(fn () => $method->invoke($service, $companyB, $dbNameA, false))
            ->toThrow(ProvisioningException::class, 'pertenece a otra empresa');
    });

    test('14. absence of secrets in output, logs and serialized connections', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('secrets', $cleanupCompanies);

        $conn = $service->provision($company);

        // 1. Serialized connection does not leak passwords
        $array = $conn->toArray();
        expect($array)->not->toHaveKey('app_password')
            ->and($array)->not->toHaveKey('migration_password');

        // 2. Provisioning status does not leak passwords
        $status = $service->getStatus($company);
        expect(json_encode($status))->not->toContain($conn->app_password)
            ->and(json_encode($status))->not->toContain($conn->migration_password);

        // 3. Provisioning logs do not contain passwords
        $logs = ProvisioningLog::where('company_id', $company->id)->get();
        foreach ($logs as $log) {
            $logString = json_encode($log->details).($log->error_message ?? '');
            expect($logString)->not->toContain($conn->app_password)
                ->and($logString)->not->toContain($conn->migration_password);
        }
    });

    test('15. platform provisioning endpoints are protected by authorization', function () use (&$cleanupCompanies) {
        $company = createTestCompany('api-auth', $cleanupCompanies);

        // Unauthenticated
        $this->getJson("/platform/companies/{$company->id}/provisioning")
            ->assertUnauthorized();

        // Operator with platform.provisioning.view
        $viewPerm = Permission::firstOrCreate(
            ['slug' => 'platform.provisioning.view'],
            ['scope' => PermissionScope::Platform, 'name' => 'Ver Aprovisionamiento']
        );
        $viewRole = PlatformRole::firstOrCreate(
            ['slug' => 'prov_viewer'],
            ['name' => 'Visor de Aprovisionamiento']
        );
        $viewRole->permissions()->syncWithoutDetaching([$viewPerm->id]);

        $user = User::factory()->create([
            'status' => UserStatus::Active,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->platformRoles()->sync([$viewRole->id]);

        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/platform/companies/{$company->id}/provisioning")
            ->assertOk()
            ->assertJsonPath('provisioning.status', 'pending');

        // Trying to provision without platform.provisioning.manage gives 403 Forbidden
        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/platform/companies/{$company->id}/provision")
            ->assertForbidden();
    });
});
