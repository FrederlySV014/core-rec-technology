<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Models\CompanyDatabaseConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

describe('PostgreSQL Native Engine Constraints & Triggers', function () {
    beforeEach(function () {
        $this->company = Company::create([
            'legal_name' => 'Postgres Engine Test SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20999999999',
            'slug' => 'postgres-engine-test',
            'contact_email' => 'pg@example.com',
        ]);
    });

    test('postgres exclusion constraint physically rejects overlapping active licenses on direct raw insert', function () {
        $start1 = Carbon::parse('2026-01-01 00:00:00+00');
        $end1 = Carbon::parse('2026-06-01 00:00:00+00');

        // Insert first license directly with raw SQL
        DB::connection('central')->table('licenses')->insert([
            'company_id' => $this->company->id,
            'license_key' => 'RAW-LIC-001',
            'plan_name' => 'Plan A',
            'administrative_status' => 'active',
            'starts_at' => $start1,
            'expires_at' => $end1,
            'effective_ends_at' => $end1,
            'grace_period_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt direct raw SQL insert overlapping with license 1
        expect(fn () => DB::connection('central')->table('licenses')->insert([
            'company_id' => $this->company->id,
            'license_key' => 'RAW-LIC-002-OVERLAP',
            'plan_name' => 'Plan B',
            'administrative_status' => 'active',
            'starts_at' => Carbon::parse('2026-03-01 00:00:00+00'),
            'expires_at' => Carbon::parse('2026-09-01 00:00:00+00'),
            'effective_ends_at' => Carbon::parse('2026-09-01 00:00:00+00'),
            'grace_period_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('postgres trigger physically rejects direct insert of company permission into platform_role_permissions', function () {
        $platformRole = PlatformRole::create([
            'name' => 'SaaS Admin',
            'slug' => 'saas_admin',
        ]);

        $companyPermission = Permission::create([
            'scope' => PermissionScope::Company,
            'name' => 'Facturación Comercial',
            'slug' => 'commercial.billing.issue',
        ]);

        // Attempt direct raw SQL insert of company permission into platform_role_permissions
        expect(fn () => DB::connection('central')->table('platform_role_permissions')->insert([
            'platform_role_id' => $platformRole->id,
            'permission_id' => $companyPermission->id,
        ]))->toThrow(QueryException::class);
    });

    test('postgres trigger physically rejects direct insert of platform permission into company_role_permissions', function () {
        $companyRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'name' => 'Administrador Local',
            'slug' => 'admin_local',
        ]);

        $platformPermission = Permission::create([
            'scope' => PermissionScope::Platform,
            'name' => 'Administrar Empresas SaaS',
            'slug' => 'platform.companies.manage',
        ]);

        // Attempt direct raw SQL insert of platform permission into company_role_permissions
        expect(fn () => DB::connection('central')->table('company_role_permissions')->insert([
            'company_role_id' => $companyRole->id,
            'permission_id' => $platformPermission->id,
        ]))->toThrow(QueryException::class);
    });

    test('postgres case-insensitive index physically rejects direct insert of email differing only by case', function () {
        User::factory()->create([
            'email' => 'admin@rec-technology.com',
        ]);

        expect(fn () => DB::connection('central')->table('users')->insert([
            'name' => 'Duplicated User',
            'email' => 'ADMIN@REC-TECHNOLOGY.COM', // Same email in uppercase
            'password' => 'secret',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('postgres unique constraint rejects sharing the same database target between companies', function () {
        CompanyDatabaseConnection::create([
            'company_id' => $this->company->id,
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'tenant_db_exclusive',
            'schema' => 'public',
            'app_username' => 'app_user',
            'app_password' => 'secret',
        ]);

        $company2 = Company::create([
            'legal_name' => 'Company Two SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20888888889',
            'slug' => 'company-two',
            'contact_email' => 'c2@example.com',
        ]);

        // Attempting to attach company2 to the exact same host/port/database even with another schema must fail
        expect(fn () => CompanyDatabaseConnection::create([
            'company_id' => $company2->id,
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'tenant_db_exclusive',
            'schema' => 'tenant2_schema', // Different schema! Still must fail
            'app_username' => 'app_user2',
            'app_password' => 'secret2',
        ]))->toThrow(QueryException::class);
    });

    test('postgres trigger automatically synchronizes effective_ends_at on direct raw insert without it', function () {
        $startsAt = Carbon::parse('2026-01-01 00:00:00+00');
        $expiresAt = Carbon::parse('2026-06-01 00:00:00+00');

        DB::connection('central')->table('licenses')->insert([
            'company_id' => $this->company->id,
            'license_key' => 'RAW-AUTO-SYNC-001',
            'plan_name' => 'Plan Auto',
            'administrative_status' => 'active',
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'effective_ends_at' => null, // Left NULL directly
            'grace_period_days' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inserted = DB::connection('central')->table('licenses')->where('license_key', 'RAW-AUTO-SYNC-001')->first();
        expect($inserted->effective_ends_at)->not->toBeNull();

        $expected = $expiresAt->copy()->addDays(5)->toIso8601String();
        expect(Carbon::parse($inserted->effective_ends_at)->toIso8601String())->toBe($expected);
    });

    test('postgres trigger physically rejects direct raw insert with effective_ends_at earlier than expires_at', function () {
        expect(fn () => DB::connection('central')->table('licenses')->insert([
            'company_id' => $this->company->id,
            'license_key' => 'RAW-INVALID-END-001',
            'plan_name' => 'Plan Invalid',
            'administrative_status' => 'active',
            'starts_at' => Carbon::parse('2026-01-01 00:00:00+00'),
            'expires_at' => Carbon::parse('2026-06-01 00:00:00+00'),
            'effective_ends_at' => Carbon::parse('2026-05-01 00:00:00+00'), // Earlier than expires_at!
            'grace_period_days' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('postgres trigger physically rejects direct raw insert with expires_at earlier than starts_at', function () {
        expect(fn () => DB::connection('central')->table('licenses')->insert([
            'company_id' => $this->company->id,
            'license_key' => 'RAW-INVALID-START-001',
            'plan_name' => 'Plan Invalid',
            'administrative_status' => 'active',
            'starts_at' => Carbon::parse('2026-06-01 00:00:00+00'),
            'expires_at' => Carbon::parse('2026-01-01 00:00:00+00'), // Earlier than starts_at!
            'grace_period_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
