<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\RoleTemplate;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use App\Modules\Tenancy\Exceptions\TenantContextException;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Commercial Product Catalog & Tenancy Adjustments (Phase 7)', function () {
    $cleanupCompanies = [];

    beforeEach(function () {
        Artisan::call('db:seed', ['--class' => 'CentralPermissionSeeder']);
        Artisan::call('db:seed', ['--class' => 'CentralRoleTemplateSeeder']);
    });

    afterEach(function () use (&$cleanupCompanies) {
        $provisioner = app(TenantProvisioningService::class);
        $manager = app(TenantConnectionManager::class);
        $manager->disconnect();

        foreach ($cleanupCompanies as $company) {
            try {
                $provisioner->dropTenantDatabase($company);
                $company->forceDelete();
            } catch (Throwable) {
                // Ignore teardown errors
            }
        }
        $cleanupCompanies = [];
    });

    function setupCatalogModuleAndPermissions(): array
    {
        $inventoryModule = Module::firstOrCreate(
            ['slug' => 'inventory'],
            ['name' => 'Inventario y Almacenes', 'is_core' => false, 'status' => 'active']
        );

        $viewPerm = Permission::firstOrCreate(
            ['slug' => 'inventory.products.view'],
            ['scope' => PermissionScope::Company, 'module_id' => $inventoryModule->id, 'name' => 'Ver Catálogo']
        );

        $prodManagePerm = Permission::firstOrCreate(
            ['slug' => 'inventory.products.manage'],
            ['scope' => PermissionScope::Company, 'module_id' => $inventoryModule->id, 'name' => 'Administrar Productos']
        );

        $catManagePerm = Permission::firstOrCreate(
            ['slug' => 'inventory.categories.manage'],
            ['scope' => PermissionScope::Company, 'module_id' => $inventoryModule->id, 'name' => 'Administrar Categorías']
        );

        $unitManagePerm = Permission::firstOrCreate(
            ['slug' => 'inventory.units.manage'],
            ['scope' => PermissionScope::Company, 'module_id' => $inventoryModule->id, 'name' => 'Administrar Unidades']
        );

        return [
            'module' => $inventoryModule,
            'view' => $viewPerm,
            'prod_manage' => $prodManagePerm,
            'cat_manage' => $catManagePerm,
            'unit_manage' => $unitManagePerm,
        ];
    }

    function createCatalogTestCompany(string $suffix, array &$cleanupList, bool $provision = true, array $rolePermissions = []): array
    {
        $perms = setupCatalogModuleAndPermissions();

        $unique = Str::lower(Str::random(6));
        $company = Company::create([
            'legal_name' => "Catalog Co {$suffix} {$unique} S.A.C.",
            'trade_name' => "Catalog {$suffix}",
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20'.rand(100000000, 999999999),
            'slug' => "catalog-{$suffix}-{$unique}",
            'contact_email' => "catalog.{$suffix}.{$unique}@example.com",
            'administrative_status' => CompanyStatus::Active,
            'technical_provisioning_status' => ProvisioningStatus::Pending,
        ]);

        $cleanupList[] = $company;

        $license = License::create([
            'company_id' => $company->id,
            'license_key' => 'LIC-CAT-'.Str::upper(Str::random(10)),
            'plan_name' => 'Plan Empresarial Pro',
            'max_users' => 15,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'grace_period_days' => 15,
            'administrative_status' => LicenseAdministrativeStatus::Active,
        ]);
        $license->modules()->sync([$perms['module']->id]);

        $role = CompanyRole::create([
            'company_id' => $company->id,
            'name' => "Rol {$suffix}",
            'slug' => "rol-{$suffix}-{$unique}",
        ]);

        $permIds = [];
        foreach ($rolePermissions as $permSlug) {
            if (isset($perms[$permSlug])) {
                $permIds[] = $perms[$permSlug]->id;
            } elseif ($p = Permission::where('slug', $permSlug)->first()) {
                $permIds[] = $p->id;
            }
        }
        $role->permissions()->sync($permIds);

        if ($provision) {
            $provisioner = app(TenantProvisioningService::class);
            try {
                $provisioner->dropTenantDatabase($company);
            } catch (Throwable) {
            }
            $provisioner->provision($company);
            $company->refresh();
        }

        return [
            'company' => $company,
            'license' => $license,
            'role' => $role,
            'perms' => $perms,
        ];
    }

    function createCatalogActiveUser(): User
    {
        return User::factory()->create([
            'status' => UserStatus::Active,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    function assignCatalogMembership(Company $company, User $user, CompanyRole $role): Membership
    {
        $membership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'is_owner' => false,
        ]);
        $membership->assignRole($role);

        return $membership;
    }

    // -------------------------------------------------------------
    // AJUSTE 1: PERMISOS EXPLÍCITOS (SIN AUTORIZACIÓN IMPLÍCITA)
    // -------------------------------------------------------------
    describe('1. Explicit Permissions Separation', function () use (&$cleanupCompanies) {
        test('user with only inventory.products.manage cannot manage categories or units', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('perm-prod-only', $cleanupCompanies, true, ['view', 'prod_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            // Can manage product
            // First we need an active unit in tenant DB
            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $manager->disconnect();

            $response = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'PROD-001',
                    'name' => 'Producto Prueba',
                    'unit_of_measure_id' => $unit->id,
                ]);
            $response->assertStatus(201);

            // Attempt to create a category -> 403 Forbidden!
            $catResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/categories", [
                    'name' => 'Categoría No Autorizada',
                ]);
            $catResponse->assertStatus(403);

            // Attempt to create a unit -> 403 Forbidden!
            $unitResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/units", [
                    'code' => 'KGM',
                    'name' => 'Kilogramo',
                ]);
            $unitResponse->assertStatus(403);
        });

        test('user with inventory.categories.manage can manage categories but cannot manage products or units', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('perm-cat-only', $cleanupCompanies, true, ['view', 'cat_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            // Can create category
            $catResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/categories", [
                    'name' => 'Categoría Permitida',
                ]);
            $catResponse->assertStatus(201);

            // Cannot create unit
            $unitResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/units", [
                    'code' => 'KGM',
                    'name' => 'Kilogramo',
                ]);
            $unitResponse->assertStatus(403);

            // Cannot create product
            $prodResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'PROD-002',
                    'name' => 'Producto No Autorizado',
                    'unit_of_measure_id' => 1,
                ]);
            $prodResponse->assertStatus(403);
        });

        test('user with inventory.units.manage can manage units but cannot manage categories or products', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('perm-unit-only', $cleanupCompanies, true, ['view', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            // Can create unit
            $unitResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/units", [
                    'code' => 'MTR',
                    'name' => 'Metro',
                ]);
            $unitResponse->assertStatus(201);

            // Cannot create category
            $catResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/categories", [
                    'name' => 'Categoría No Autorizada',
                ]);
            $catResponse->assertStatus(403);

            // Cannot create product
            $prodResponse = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'PROD-003',
                    'name' => 'Producto No Autorizado',
                    'unit_of_measure_id' => 1,
                ]);
            $prodResponse->assertStatus(403);
        });

        test('standard role template company_admin has explicit assignments to categories and units manage', function () {
            $adminTemplate = RoleTemplate::where('slug', 'company_admin')->first();
            expect($adminTemplate)->not->toBeNull();

            $perms = $adminTemplate->permissions->pluck('slug')->all();
            expect($perms)->toContain('inventory.products.view')
                ->toContain('inventory.products.manage')
                ->toContain('inventory.categories.manage')
                ->toContain('inventory.units.manage');
        });
    });

    // -------------------------------------------------------------
    // AJUSTE 2: CONEXIÓN EFECTIVA DE MIGRACIONES (TENANT:MIGRATE)
    // -------------------------------------------------------------
    describe('2. Effective Migration Connection & Runner', function () use (&$cleanupCompanies) {
        test('tenant:migrate runs on an already provisioned company using migration credentials', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('mig-existing', $cleanupCompanies, true);
            $company = $setup['company'];

            // Run tenant:migrate CLI command
            $exitCode = Artisan::call('tenant:migrate', [
                'company' => $company->slug,
            ]);
            expect($exitCode)->toBe(0);

            // Connect as tenant_app to verify tables and migrations exist in the tenant database
            $manager = app(TenantConnectionManager::class);
            $manager->connect($company);

            $tables = DB::connection('tenant')->select("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name IN ('categories', 'units_of_measure', 'products')
            ");
            $tableNames = array_map(fn ($r) => $r->table_name, $tables);

            expect($tableNames)->toContain('categories')
                ->toContain('units_of_measure')
                ->toContain('products');

            $manager->disconnect();
        });

        test('newly provisioned company executes all tenant migrations during provisioning', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('mig-new', $cleanupCompanies, true);
            $company = $setup['company'];

            $manager = app(TenantConnectionManager::class);
            $manager->connect($company);

            // Verify categories, units_of_measure and products were created during initial provisioning
            expect(Category::count())->toBe(0);
            expect(UnitOfMeasure::count())->toBe(0);
            expect(Product::count())->toBe(0);

            $manager->disconnect();
        });
    });

    // -------------------------------------------------------------
    // AJUSTE 3: PRIVILEGIOS LIMITADOS AL ERP (LEAST PRIVILEGE)
    // -------------------------------------------------------------
    describe('3. ERP-Scoped Privileges & Isolation from Non-ERP Tables', function () use (&$cleanupCompanies) {
        test('non-ERP table does not receive privileges when tenant:migrate executes', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('least-priv', $cleanupCompanies, true);
            $company = $setup['company']->fresh();
            $connConfig = $company->databaseConnection;

            $provisioner = app(TenantProvisioningService::class);
            $dbPdo = $provisioner->getDatabaseAdminPdo($connConfig->database);

            // Create a foreign / non-ERP table in the commercial database (e.g. ecommerce store table)
            $dbPdo->exec('DROP TABLE IF EXISTS store_foreign_orders');
            $dbPdo->exec('CREATE TABLE store_foreign_orders (id serial primary key, order_ref text)');

            // Re-run tenant:migrate
            $exitCode = Artisan::call('tenant:migrate', [
                'company' => $company->id,
            ]);
            expect($exitCode)->toBe(0);

            // Connect using application credentials (tenant connection)
            $manager = app(TenantConnectionManager::class);
            $manager->connect($company);

            // 1. ERP tables must be accessible for DML
            $cat = Category::create(['name' => 'Cat ERP Priv Test', 'is_active' => true]);
            expect($cat->id)->toBeGreaterThan(0);

            // 2. Foreign / store table must be completely inaccessible to ERP app user
            $denied = false;
            try {
                DB::connection('tenant')->table('store_foreign_orders')->get();
            } catch (QueryException $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'permission denied') || str_contains($msg, 'permiso denegado')) {
                    $denied = true;
                }
            }
            expect($denied)->toBeTrue();

            // 3. tenant_identity table must remain strictly read-only for ERP app user
            $identityUpdateDenied = false;
            try {
                DB::connection('tenant')->table('tenant_identity')->update(['tax_id' => 'tamper']);
            } catch (QueryException $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'permission denied') || str_contains($msg, 'permiso denegado')) {
                    $identityUpdateDenied = true;
                }
            }
            expect($identityUpdateDenied)->toBeTrue();

            $manager->disconnect();
        });
    });

    // -------------------------------------------------------------
    // AJUSTE 4: REFERENCIAS INACTIVAS
    // -------------------------------------------------------------
    describe('4. Inactive References Policy', function () use (&$cleanupCompanies) {
        test('allows editing product keeping its current inactive category and unit, but rejects assigning different inactive references', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('inactive-refs', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);

            // Create initial active category and unit
            $cat1 = Category::create(['name' => 'Categoría Original', 'is_active' => true]);
            $cat2 = Category::create(['name' => 'Otra Categoría', 'is_active' => false]); // Inactive

            $unit1 = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad', 'is_active' => true]);
            $unit2 = UnitOfMeasure::create(['code' => 'KGM', 'name' => 'Kilogramo', 'is_active' => false]); // Inactive

            // Create product with cat1 and unit1
            $product = Product::create([
                'sku' => 'PROD-ORIG',
                'name' => 'Producto Referencias',
                'category_id' => $cat1->id,
                'unit_of_measure_id' => $unit1->id,
                'is_active' => true,
            ]);

            // Now deactivate cat1 and unit1
            $cat1->update(['is_active' => false]);
            $unit1->update(['is_active' => false]);

            $manager->disconnect();

            // Case A: Update product retaining current inactive cat1 and inactive unit1 -> MUST SUCCEED (200)
            $responseA = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->putJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}", [
                    'sku' => 'PROD-ORIG',
                    'name' => 'Producto Nombre Actualizado',
                    'category_id' => $cat1->id, // Same inactive category retained
                    'unit_of_measure_id' => $unit1->id, // Same inactive unit retained
                ]);
            $responseA->assertStatus(200);

            // Case B: Update product assigning DIFFERENT inactive category cat2 -> MUST FAIL (422)
            $responseB = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->putJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}", [
                    'sku' => 'PROD-ORIG',
                    'name' => 'Producto Intento',
                    'category_id' => $cat2->id, // Different inactive category!
                    'unit_of_measure_id' => $unit1->id,
                ]);
            $responseB->assertStatus(422)
                ->assertJsonValidationErrors(['category_id']);

            // Case C: Update product assigning DIFFERENT inactive unit unit2 -> MUST FAIL (422)
            $responseC = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->putJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}", [
                    'sku' => 'PROD-ORIG',
                    'name' => 'Producto Intento',
                    'category_id' => $cat1->id,
                    'unit_of_measure_id' => $unit2->id, // Different inactive unit!
                ]);
            $responseC->assertStatus(422)
                ->assertJsonValidationErrors(['unit_of_measure_id']);

            // Case D: Creating a brand new product with an inactive category -> MUST FAIL (422)
            $responseD = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'PROD-NEW-FAIL',
                    'name' => 'Producto Invalido',
                    'category_id' => $cat2->id,
                    'unit_of_measure_id' => $unit1->id,
                ]);
            $responseD->assertStatus(422)
                ->assertJsonValidationErrors(['category_id']);
        });
    });

    // -------------------------------------------------------------
    // AJUSTE 5: CAMBIOS DE ESTADO EXPLÍCITOS E IDEMPOTENTES
    // -------------------------------------------------------------
    describe('5. Explicit and Idempotent Status Operations', function () use (&$cleanupCompanies) {
        test('explicit status endpoint sets desired state idempotently', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('status-idempotent', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $product = Product::create([
                'sku' => 'PROD-STAT',
                'name' => 'Producto Estado',
                'unit_of_measure_id' => $unit->id,
                'is_active' => true,
            ]);
            $manager->disconnect();

            // Deactivate explicitly
            $resp1 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->patchJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}/status", [
                    'is_active' => false,
                ]);
            $resp1->assertStatus(200);
            expect($resp1->json('product.is_active'))->toBeFalse();

            // Repeat the same request -> must conserve the same result (idempotent, no toggle flip!)
            $resp2 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->patchJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}/status", [
                    'is_active' => false,
                ]);
            $resp2->assertStatus(200);
            expect($resp2->json('product.is_active'))->toBeFalse();

            // Activate explicitly
            $resp3 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->patchJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}/status", [
                    'is_active' => true,
                ]);
            $resp3->assertStatus(200);
            expect($resp3->json('product.is_active'))->toBeTrue();

            // Repeat activate -> stays true
            $resp4 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->patchJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}/status", [
                    'is_active' => true,
                ]);
            $resp4->assertStatus(200);
            expect($resp4->json('product.is_active'))->toBeTrue();
        });

        test('status updates are rejected if company license is in read-only mode', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('status-readonly', $cleanupCompanies, true, ['view', 'prod_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $product = Product::create([
                'sku' => 'PROD-RO',
                'name' => 'Producto RO',
                'unit_of_measure_id' => $unit->id,
                'is_active' => true,
            ]);
            $manager->disconnect();

            // Move license into grace period (read-only mode)
            $setup['license']->update([
                'starts_at' => now()->subMonths(13),
                'expires_at' => now()->subDay(), // Expired yesterday -> within 15 days grace period
            ]);

            $resp = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->patchJson("/app/{$setup['company']->slug}/catalog/products/{$product->id}/status", [
                    'is_active' => false,
                ]);
            $resp->assertStatus(403);
        });
    });

    // -------------------------------------------------------------
    // AJUSTE 6: UNIDADES INTERNAS Y CATÁLOGO BÁSICO
    // -------------------------------------------------------------
    describe('6. Internal Unit Codes & Normalization', function () use (&$cleanupCompanies) {
        test('unit code is trimmed and capitalized, and unique per tenant', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('unit-norm', $cleanupCompanies, true, ['view', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            // Create unit with lowercase and padding
            $resp1 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/units", [
                    'code' => '  kgm  ',
                    'name' => 'Kilogramo',
                    'allows_decimal' => true,
                ]);
            $resp1->assertStatus(201);
            expect($resp1->json('unit.code'))->toBe('KGM');
            expect($resp1->json('unit.allows_decimal'))->toBeTrue();

            // Attempt duplicate code in same company -> 422
            $resp2 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/units", [
                    'code' => 'KGM',
                    'name' => 'Otro Kilogramo',
                ]);
            $resp2->assertStatus(422)
                ->assertJsonValidationErrors(['code']);
        });

        test('product SKU is trimmed and capitalized, and unique per tenant', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('sku-norm', $cleanupCompanies, true, ['view', 'prod_manage', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $manager->disconnect();

            $resp1 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => '  item-abc  ',
                    'name' => 'Artículo ABC',
                    'unit_of_measure_id' => $unit->id,
                ]);
            $resp1->assertStatus(201);
            expect($resp1->json('product.sku'))->toBe('ITEM-ABC');

            // Duplicate SKU -> 422
            $resp2 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'ITEM-ABC',
                    'name' => 'Artículo Duplicado',
                    'unit_of_measure_id' => $unit->id,
                ]);
            $resp2->assertStatus(422)
                ->assertJsonValidationErrors(['sku']);
        });

        test('barcode is optional and unique when provided but allows multiple nulls', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('barcode-null', $cleanupCompanies, true, ['view', 'prod_manage', 'unit_manage']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $manager = app(TenantConnectionManager::class);
            $manager->connect($setup['company']);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $manager->disconnect();

            // Product 1 with null barcode
            $r1 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'P-1',
                    'name' => 'Prod 1',
                    'unit_of_measure_id' => $unit->id,
                    'barcode' => null,
                ]);
            $r1->assertStatus(201);

            // Product 2 with null barcode -> allowed!
            $r2 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'P-2',
                    'name' => 'Prod 2',
                    'unit_of_measure_id' => $unit->id,
                    'barcode' => '',
                ]);
            $r2->assertStatus(201);

            // Product 3 with barcode
            $r3 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'P-3',
                    'name' => 'Prod 3',
                    'unit_of_measure_id' => $unit->id,
                    'barcode' => '7750001',
                ]);
            $r3->assertStatus(201);

            // Product 4 with duplicate barcode -> 422
            $r4 = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->postJson("/app/{$setup['company']->slug}/catalog/products", [
                    'sku' => 'P-4',
                    'name' => 'Prod 4',
                    'unit_of_measure_id' => $unit->id,
                    'barcode' => '7750001',
                ]);
            $r4->assertStatus(422)
                ->assertJsonValidationErrors(['barcode']);
        });

        test('catalog index endpoint returns products, categories, units, and stats', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('index-full', $cleanupCompanies, true, ['view']);
            $user = createCatalogActiveUser();
            assignCatalogMembership($setup['company'], $user, $setup['role']);

            $response = $this->actingAs($user)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->getJson("/app/{$setup['company']->slug}/catalog");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'company' => ['legal_name', 'slug'],
                    'products',
                    'categories',
                    'units',
                    'stats' => ['total_products', 'active_products', 'total_categories', 'total_units'],
                    'can' => ['manage_products', 'manage_categories', 'manage_units'],
                ]);
        });
    });

    // -------------------------------------------------------------
    // CRITERIO 1: OPERACIONES AISLADAS ENTRE EMPRESAS A Y B
    // -------------------------------------------------------------
    describe('7. Multi-Tenant Cross-Company Catalog Isolation (A & B)', function () use (&$cleanupCompanies) {
        test('allows same SKU, equal IDs, isolated operations and clean A -> B -> A sequence', function () use (&$cleanupCompanies) {
            $setupA = createCatalogTestCompany('co-alpha', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $userA = createCatalogActiveUser();
            assignCatalogMembership($setupA['company'], $userA, $setupA['role']);

            $setupB = createCatalogTestCompany('co-beta', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $userB = createCatalogActiveUser();
            assignCatalogMembership($setupB['company'], $userB, $setupB['role']);

            $manager = app(TenantConnectionManager::class);

            // Step 1: In Company A, create Unit, Category and Product with SKU 'SKU-COMMON'
            $manager->connect($setupA['company']);
            $unitA = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad A', 'is_active' => true]);
            $catA = Category::create(['name' => 'Categoría A', 'is_active' => true]);
            $prodA = Product::create([
                'sku' => 'SKU-COMMON',
                'name' => 'Producto Empresa A',
                'category_id' => $catA->id,
                'unit_of_measure_id' => $unitA->id,
                'is_active' => true,
            ]);
            $idA = $prodA->id;
            $manager->disconnect();

            // Step 2: In Company B, create Unit, Category and Product with SAME SKU 'SKU-COMMON'
            $manager->connect($setupB['company']);
            $unitB = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad B', 'is_active' => true]);
            $catB = Category::create(['name' => 'Categoría B', 'is_active' => true]);
            $prodB = Product::create([
                'sku' => 'SKU-COMMON', // Same SKU allowed across different tenants!
                'name' => 'Producto Empresa B',
                'category_id' => $catB->id,
                'unit_of_measure_id' => $unitB->id,
                'is_active' => true,
            ]);
            $idB = $prodB->id;
            $manager->disconnect();

            // Assert both databases allocated primary keys independently (equal identifiers possible)
            expect($idA)->toBe($idB)
                ->and($prodA->sku)->toBe('SKU-COMMON')
                ->and($prodB->sku)->toBe('SKU-COMMON');

            // Step 3: Sequence A -> B -> A via HTTP requests

            // Request 1: Query Company A via HTTP
            $respA1 = $this->actingAs($userA)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->getJson("/app/{$setupA['company']->slug}/catalog");
            $respA1->assertStatus(200);
            expect($respA1->json('products.0.name'))->toBe('Producto Empresa A')
                ->and($respA1->json('products.0.sku'))->toBe('SKU-COMMON');

            // Request 2: Mutate Company B via HTTP
            $respB = $this->actingAs($userB)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->putJson("/app/{$setupB['company']->slug}/catalog/products/{$idB}", [
                    'sku' => 'SKU-COMMON',
                    'name' => 'Producto Empresa B Modificado',
                    'category_id' => $catB->id,
                    'unit_of_measure_id' => $unitB->id,
                ]);
            $respB->assertStatus(200);
            expect($respB->json('product.name'))->toBe('Producto Empresa B Modificado');

            // Request 3: Return to Company A (A -> B -> A sequence)
            // Verify Company A data is completely unchanged (no cross-tenant leakage or mutation)
            $respA2 = $this->actingAs($userA)
                ->withSession(['company_2fa_verified_at' => now()->timestamp])
                ->getJson("/app/{$setupA['company']->slug}/catalog");
            $respA2->assertStatus(200);
            expect($respA2->json('products.0.name'))->toBe('Producto Empresa A')
                ->and($respA2->json('products.0.name'))->not->toBe('Producto Empresa B Modificado');
        });
    });

    // -------------------------------------------------------------
    // CRITERIO 2: PROTECCIÓN DE MODELOS Y CONSULTAS ENTRE EMPRESAS
    // -------------------------------------------------------------
    describe('8. Model and Query Tenant Context Binding Protection', function () use (&$cleanupCompanies) {
        test('model instantiated in tenant A cannot be saved or deleted in tenant B', function () use (&$cleanupCompanies) {
            $setupA = createCatalogTestCompany('bind-a', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $setupB = createCatalogTestCompany('bind-b', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);

            $manager = app(TenantConnectionManager::class);

            // Connect to Company A and create product
            $manager->connect($setupA['company']);
            $unitA = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad A', 'is_active' => true]);
            $prodA = Product::create([
                'sku' => 'PROD-A',
                'name' => 'Producto de A',
                'unit_of_measure_id' => $unitA->id,
                'is_active' => true,
            ]);
            $manager->disconnect();

            // Connect to Company B and create product in B with same ID
            $manager->connect($setupB['company']);
            $unitB = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad B', 'is_active' => true]);
            $prodB = Product::create([
                'sku' => 'PROD-B',
                'name' => 'Producto de B',
                'unit_of_measure_id' => $unitB->id,
                'is_active' => true,
            ]);

            // Attempting to mutate $prodA while Company B is connected MUST throw TenantContextException!
            $prodA->name = 'Mutación Ilegal en B';
            expect(fn () => $prodA->save())->toThrow(
                TenantContextException::class,
                "Operación sobre modelo comercial rechazada: el modelo fue instanciado para la empresa [{$setupA['company']->id}]"
            );

            // Attempting to delete $prodA while Company B is connected MUST throw TenantContextException!
            expect(fn () => $prodA->delete())->toThrow(
                TenantContextException::class,
                "Operación sobre modelo comercial rechazada: el modelo fue instanciado para la empresa [{$setupA['company']->id}]"
            );

            // Verify Company B product remains completely untouched
            $freshB = Product::find($prodB->id);
            expect($freshB->name)->toBe('Producto de B');

            $manager->disconnect();
        });

        test('query builder constructed in tenant A cannot be executed in tenant B', function () use (&$cleanupCompanies) {
            $setupA = createCatalogTestCompany('query-a', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $setupB = createCatalogTestCompany('query-b', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);

            $manager = app(TenantConnectionManager::class);

            // Connect to Company A and construct query
            $manager->connect($setupA['company']);
            $unitA = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad A', 'is_active' => true]);
            Product::create([
                'sku' => 'QUERY-A',
                'name' => 'Producto Query A',
                'unit_of_measure_id' => $unitA->id,
                'is_active' => true,
            ]);
            $queryA = Product::where('sku', 'QUERY-A');
            $manager->disconnect();

            // Connect to Company B
            $manager->connect($setupB['company']);

            // Attempting to execute $queryA->get() while B is active MUST throw TenantContextException!
            expect(fn () => $queryA->get())->toThrow(
                TenantContextException::class,
                "Operación de consulta comercial rechazada: la consulta fue construida para la empresa [{$setupA['company']->id}]"
            );

            // Attempting to execute $queryA->update() while B is active MUST throw TenantContextException!
            expect(fn () => $queryA->update(['name' => 'Leak']))->toThrow(
                TenantContextException::class,
                "Operación de consulta comercial rechazada: la consulta fue construida para la empresa [{$setupA['company']->id}]"
            );

            // Attempting to execute $queryA->delete() while B is active MUST throw TenantContextException!
            expect(fn () => $queryA->delete())->toThrow(
                TenantContextException::class,
                "Operación de consulta comercial rechazada: la consulta fue construida para la empresa [{$setupA['company']->id}]"
            );

            $manager->disconnect();
        });
    });

    // -------------------------------------------------------------
    // CRITERIO 3: PRESERVACIÓN DE DATOS EN TENANT:MIGRATE Y RESTRICCIÓN DDL
    // -------------------------------------------------------------
    describe('9. Tenant Migrate Data Preservation & DDL Restriction', function () use (&$cleanupCompanies) {
        test('tenant:migrate preserves existing catalog rows and keeps app user restricted from DDL', function () use (&$cleanupCompanies) {
            $setup = createCatalogTestCompany('migrate-data', $cleanupCompanies, true, ['view', 'prod_manage', 'cat_manage', 'unit_manage']);
            $manager = app(TenantConnectionManager::class);

            // 1. Populate initial data in the company
            $manager->connect($setup['company']);
            $cat = Category::create(['name' => 'Electrónica', 'is_active' => true]);
            $unit = UnitOfMeasure::create(['code' => 'NIU', 'name' => 'Unidad', 'is_active' => true]);
            $prod = Product::create([
                'sku' => 'PROD-KEEP',
                'name' => 'Teléfono Inteligente',
                'category_id' => $cat->id,
                'unit_of_measure_id' => $unit->id,
                'barcode' => '77509999',
                'is_active' => true,
            ]);
            $manager->disconnect();

            // 2. Execute tenant:migrate command
            $exitCode = Artisan::call('tenant:migrate', [
                'company' => $setup['company']->slug,
            ]);
            expect($exitCode)->toBe(0);

            // 3. Connect as application user and verify all data was preserved
            $manager->connect($setup['company']);
            $freshProd = Product::where('sku', 'PROD-KEEP')->first();
            expect($freshProd)->not->toBeNull()
                ->and($freshProd->name)->toBe('Teléfono Inteligente')
                ->and($freshProd->category_id)->toBe($cat->id)
                ->and($freshProd->unit_of_measure_id)->toBe($unit->id)
                ->and($freshProd->barcode)->toBe('77509999');

            // 4. Verify app_username has full DML on the tables
            $freshProd->update(['name' => 'Teléfono Inteligente Pro']);
            expect($freshProd->fresh()->name)->toBe('Teléfono Inteligente Pro');

            // 5. Verify app_username CANNOT perform DDL operations (Least Privilege)
            $conn = DB::connection('tenant');
            expect(function () use ($conn) {
                $conn->statement('CREATE TABLE probe_ddl_test (id INT)');
            })->toThrow(QueryException::class);

            $manager->disconnect();
        });
    });
});
