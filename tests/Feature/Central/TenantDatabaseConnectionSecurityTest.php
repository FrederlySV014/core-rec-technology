<?php

use App\Modules\Companies\Models\Company;
use App\Modules\Provisioning\Models\CompanyDatabaseConnection;
use Illuminate\Support\Facades\DB;

describe('Tenant Database Connection Security & Encryption', function () {
    test('credentials are encrypted in database and never stored in plain text', function () {
        $company = Company::create([
            'legal_name' => 'Database Protected SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20888888888',
            'slug' => 'db-protected',
            'contact_email' => 'db@example.com',
        ]);

        $plainAppPassword = 'super-secret-app-password-123';
        $plainMigrationPassword = 'super-secret-mig-password-456';

        $connection = CompanyDatabaseConnection::create([
            'company_id' => $company->id,
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'tenant_db_protected',
            'schema' => 'public',
            'app_username' => 'app_user',
            'app_password' => $plainAppPassword,
            'migration_username' => 'mig_user',
            'migration_password' => $plainMigrationPassword,
        ]);

        // 1. Raw database query must NOT contain plain text password
        $rawRow = DB::connection('central')
            ->table('company_database_connections')
            ->where('id', $connection->id)
            ->first();

        expect($rawRow->app_password)->not->toBe($plainAppPassword);
        expect($rawRow->app_password)->not->toContain($plainAppPassword);
        expect($rawRow->migration_password)->not->toBe($plainMigrationPassword);

        // 2. Eloquent model decodes transparently
        $fresh = CompanyDatabaseConnection::find($connection->id);
        expect($fresh->app_password)->toBe($plainAppPassword);
        expect($fresh->migration_password)->toBe($plainMigrationPassword);

        // 3. Serialization to array / JSON must hide secret fields
        $serialized = $fresh->toArray();
        expect($serialized)->not->toHaveKey('app_password');
        expect($serialized)->not->toHaveKey('migration_password');

        // 4. Server provisioner credentials exist in protected config, not in tenant connection record
        expect(config('tenancy.provisioner.username'))->toBe('postgres');
    });
});
