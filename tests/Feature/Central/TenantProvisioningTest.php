<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\UserStatus;
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
            ->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");

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

    test('5. absence of DDL privileges and cross-tenant isolation enforcement', function () use (&$cleanupCompanies) {
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

    test('8. prevention of concurrent provisioning for the same company', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $company = createTestCompany('concurrent', $cleanupCompanies);

        $company->update(['technical_provisioning_status' => ProvisioningStatus::InProgress]);

        expect(fn () => $service->provision($company))
            ->toThrow(ProvisioningException::class, 'Ya existe un proceso de aprovisionamiento en curso');
    });

    test('9. rejection of existing database with mismatched identity', function () use (&$cleanupCompanies) {
        $service = app(TenantProvisioningService::class);
        $companyA = createTestCompany('mismatch-a', $cleanupCompanies);
        $companyB = createTestCompany('mismatch-b', $cleanupCompanies);

        // Provision Company A
        $service->provision($companyA);

        // Set Company B's database name to Company A's database name
        // (Simulate adopting an existing DB that belongs to company A)
        $dbNameA = $service->deriveDatabaseName($companyA);

        Config::set('tenancy.provisioner.db_prefix', 'forced_mismatch_');
        // We temporarily override the derive method by creating database with Company A's id
        // Or directly invoking verifyExistingDatabaseBelongsToCompany:
        $method = new ReflectionMethod($service, 'verifyExistingDatabaseBelongsToCompany');
        $method->setAccessible(true);

        expect(fn () => $method->invoke($service, $companyB, $dbNameA, false))
            ->toThrow(ProvisioningException::class, 'pertenece a otra empresa');
    });

    test('10. absence of secrets in output, logs and serialized connections', function () use (&$cleanupCompanies) {
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

    test('11. platform provisioning endpoints are protected by authorization', function () use (&$cleanupCompanies) {
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
