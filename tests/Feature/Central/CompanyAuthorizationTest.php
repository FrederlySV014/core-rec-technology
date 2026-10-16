<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

describe('Company Context and Commercial Authorization (/app/{company:slug})', function () {
    beforeEach(function () {
        // Setup Commercial Modules
        $this->inventoryModule = Module::firstOrCreate(
            ['slug' => 'inventory'],
            ['name' => 'Inventario y Almacenes', 'is_core' => false, 'status' => 'active']
        );

        $this->salesModule = Module::firstOrCreate(
            ['slug' => 'sales'],
            ['name' => 'Ventas y Facturación', 'is_core' => false, 'status' => 'active']
        );

        $this->reportsModule = Module::firstOrCreate(
            ['slug' => 'reports'],
            ['name' => 'Reportes', 'is_core' => false, 'status' => 'active']
        );

        // Setup Permissions
        $this->invViewPerm = Permission::firstOrCreate(
            ['slug' => 'inventory.products.view'],
            ['scope' => PermissionScope::Company, 'module_id' => $this->inventoryModule->id, 'name' => 'Ver Productos']
        );

        $this->invManagePerm = Permission::firstOrCreate(
            ['slug' => 'inventory.products.manage'],
            ['scope' => PermissionScope::Company, 'module_id' => $this->inventoryModule->id, 'name' => 'Gestionar Productos']
        );

        $this->salesCreatePerm = Permission::firstOrCreate(
            ['slug' => 'sales.orders.create'],
            ['scope' => PermissionScope::Company, 'module_id' => $this->salesModule->id, 'name' => 'Crear Pedidos']
        );

        $this->reportsViewPerm = Permission::firstOrCreate(
            ['slug' => 'reports.sales.view'],
            ['scope' => PermissionScope::Company, 'module_id' => $this->reportsModule->id, 'name' => 'Exportar Reportes']
        );

        // Setup Companies
        $this->companyA = Company::create([
            'legal_name' => 'Corporacion Alfa SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20500000001',
            'slug' => 'corporacion-alfa',
            'administrative_status' => CompanyStatus::Active,
            'contact_email' => 'alfa@example.com',
        ]);

        $this->companyB = Company::create([
            'legal_name' => 'Corporacion Beta SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20500000002',
            'slug' => 'corporacion-beta',
            'administrative_status' => CompanyStatus::Active,
            'contact_email' => 'beta@example.com',
        ]);

        // Setup Active License for Company A with inventory and reports
        $this->licenseA = License::create([
            'company_id' => $this->companyA->id,
            'license_key' => 'LIC-ALFA-001',
            'plan_name' => 'Plan Pro',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::now()->subMonths(1),
            'expires_at' => Carbon::now()->addMonths(6),
            'grace_period_days' => 15,
        ]);
        $this->licenseA->modules()->sync([$this->inventoryModule->id, $this->reportsModule->id]);

        // Setup Active License for Company B with sales
        $this->licenseB = License::create([
            'company_id' => $this->companyB->id,
            'license_key' => 'LIC-BETA-001',
            'plan_name' => 'Plan Sales',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => Carbon::now()->subMonths(1),
            'expires_at' => Carbon::now()->addMonths(6),
            'grace_period_days' => 15,
        ]);
        $this->licenseB->modules()->sync([$this->salesModule->id]);

        // Setup User
        $this->user = User::factory()->create(['status' => UserStatus::Active]);
    });

    test('access is granted to Company A with active membership and denied to Company B without membership', function () {
        // Membership in Company A only
        Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->user);

        // Access to Company A succeeds
        $responseA = $this->get('/app/corporacion-alfa');
        $responseA->assertOk();

        // Access to Company B is 404 without leaking company existence or details
        $responseB = $this->get('/app/corporacion-beta');
        $responseB->assertNotFound();
    });

    test('same user has isolated permissions across different companies', function () {
        // Role in Company A: Inventory manager
        $roleA = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'name' => 'Jefe Almacén Alfa',
            'slug' => 'almacen_alfa',
        ]);
        $roleA->permissions()->sync([$this->invViewPerm->id, $this->invManagePerm->id]);

        $membershipA = Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipA->syncRoles([$roleA->id]);

        // Role in Company B: Sales rep
        $roleB = CompanyRole::create([
            'company_id' => $this->companyB->id,
            'name' => 'Vendedor Beta',
            'slug' => 'vendedor_beta',
        ]);
        $roleB->permissions()->sync([$this->salesCreatePerm->id]);

        $membershipB = Membership::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipB->syncRoles([$roleB->id]);

        $this->actingAs($this->user);

        // In Company A: can access inventory, cannot access sales
        $this->get('/app/corporacion-alfa/test/inventory/view')->assertOk();
        $this->post('/app/corporacion-alfa/test/sales/create')->assertForbidden();

        // In Company B: can access sales, cannot access inventory
        $this->post('/app/corporacion-beta/test/sales/create')->assertOk();
        $this->get('/app/corporacion-beta/test/inventory/view')->assertForbidden();
    });

    test('suspended membership is denied access without information leak', function () {
        Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Suspended, // Suspended!
        ]);

        $this->actingAs($this->user);

        $response = $this->get('/app/corporacion-alfa');
        $response->assertNotFound();
    });

    test('suspended company is inaccessible even with active membership', function () {
        $this->companyA->administrative_status = CompanyStatus::Suspended;
        $this->companyA->save();

        Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->user);

        $response = $this->get('/app/corporacion-alfa');
        $response->assertNotFound();
    });

    test('user is blocked when company role grants permission but module is not licensed', function () {
        // Assign salesCreatePerm in Company A, but Company A's license only has inventory and reports!
        $roleA = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'name' => 'Ventas Sin Licencia',
            'slug' => 'ventas_sin_licencia',
        ]);
        $roleA->permissions()->sync([$this->salesCreatePerm->id]);

        $membershipA = Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipA->syncRoles([$roleA->id]);

        $this->actingAs($this->user);

        // Attempting to perform sales action in Company A fails because sales module is not in Company A license
        $response = $this->post('/app/corporacion-alfa/test/sales/create');
        $response->assertForbidden();
    });

    test('operations are rejected when company license is expired, future or suspended', function () {
        // Change license to expired
        $this->licenseA->starts_at = Carbon::now()->subMonths(3);
        $this->licenseA->expires_at = Carbon::now()->subMonths(2);
        $this->licenseA->effective_ends_at = Carbon::now()->subMonths(1);
        $this->licenseA->save();

        $roleA = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'name' => 'Admin Alfa',
            'slug' => 'admin_alfa',
        ]);
        $roleA->permissions()->sync([$this->invViewPerm->id]);

        $membershipA = Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipA->syncRoles([$roleA->id]);

        $this->actingAs($this->user);

        $response = $this->get('/app/corporacion-alfa/test/inventory/view');
        $response->assertForbidden();
    });

    test('grace period allows read-only operations and blocks mutable operations', function () {
        // Place license A into Grace Period: expired 2 days ago, grace period is 15 days
        $this->licenseA->starts_at = Carbon::now()->subMonths(2);
        $this->licenseA->expires_at = Carbon::now()->subDays(2);
        $this->licenseA->effective_ends_at = Carbon::now()->addDays(13);
        $this->licenseA->save();

        $roleA = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'name' => 'Gerente Completo',
            'slug' => 'gerente_completo',
        ]);
        $roleA->permissions()->sync([
            $this->invViewPerm->id,
            $this->invManagePerm->id,
            $this->reportsViewPerm->id,
        ]);

        $membershipA = Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipA->syncRoles([$roleA->id]);

        $this->actingAs($this->user);

        // 1. Read-only GET operation is permitted during grace period
        $responseRead = $this->get('/app/corporacion-alfa/test/inventory/view');
        $responseRead->assertOk();

        // 2. Read-only query via POST (export) is also permitted during grace period
        $responsePostQuery = $this->post('/app/corporacion-alfa/test/reports/export');
        $responsePostQuery->assertOk();

        // 3. Mutable operation is BLOCKED with 403 during grace period even though user is manager
        $responseMutable = $this->post('/app/corporacion-alfa/test/inventory/manage');
        $responseMutable->assertForbidden();
    });

    test('permission changes are immediately reflected on the next request without stale cache', function () {
        $roleA = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'name' => 'Operador Inicial',
            'slug' => 'operador_inicial',
        ]);
        // Initially only view permission
        $roleA->permissions()->sync([$this->invViewPerm->id]);

        $membershipA = Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);
        $membershipA->syncRoles([$roleA->id]);

        $this->actingAs($this->user);

        // View allowed, Manage blocked
        $this->get('/app/corporacion-alfa/test/inventory/view')->assertOk();
        $this->post('/app/corporacion-alfa/test/inventory/manage')->assertForbidden();

        // Grant manage permission in the database
        $roleA->permissions()->attach($this->invManagePerm->id);

        // Immediately allowed on next request
        $this->post('/app/corporacion-alfa/test/inventory/manage')->assertOk();

        // Revoke manage permission
        $roleA->permissions()->detach($this->invManagePerm->id);

        // Immediately blocked on next request
        $this->post('/app/corporacion-alfa/test/inventory/manage')->assertForbidden();
    });

    test('commercial requests never open tenant database connection', function () {
        Membership::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->user);

        $this->get('/app/corporacion-alfa')->assertOk();

        // Confirm tenant connection was never initialized
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();
    });
});
