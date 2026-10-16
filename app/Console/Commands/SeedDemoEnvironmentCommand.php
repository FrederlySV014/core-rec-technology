<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use Database\Seeders\CentralDatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Fortify\RecoveryCode;

class SeedDemoEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:seed-environment 
                            {--password= : Contraseña opcional para todas las cuentas demo (por defecto genera una aleatoria segura)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prepara empresas, licencias y cuentas de demostración para validar todos los roles y escenarios de acceso en desarrollo local';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando está restringido exclusivamente a entornos locales y de desarrollo. No se puede ejecutar en producción.');

            return self::FAILURE;
        }

        $this->info('1. Sembrando catálogos base (módulos, permisos, roles de plataforma y plantillas de roles)...');
        $this->call('db:seed', ['--class' => CentralDatabaseSeeder::class, '--no-interaction' => true]);

        // Secure password resolution: option -> interactive prompt -> random generation
        $password = $this->option('password');
        if (empty($password)) {
            if ($this->input->isInteractive()) {
                $password = $this->secret('Ingrese una contraseña para las cuentas de demostración (Enter para generar una aleatoria):');
            }
            if (empty($password)) {
                $password = Str::password(16, true, true, true, false);
            }
        }

        $hashedPassword = bcrypt($password);

        // 2. Empresas y Licencias de Demostración
        $this->info('2. Configurando empresas y licencias de demostración...');

        // Empresa 1: Andina Store S.A.C. (Licencia Vigente)
        $companyA = Company::updateOrCreate(
            ['slug' => 'andina-store'],
            [
                'legal_name' => 'Andina Store S.A.C.',
                'trade_name' => 'Andina Store',
                'tax_id' => '20601234567',
                'tax_id_type' => 'RUC',
                'contact_email' => 'contacto@andina-store.pe',
                'country_code' => 'PE',
                'administrative_status' => CompanyStatus::Active,
            ]
        );

        $licenseA = License::updateOrCreate(
            ['company_id' => $companyA->id],
            [
                'license_key' => 'REC-DEMO-ANDINA-2026',
                'plan_name' => 'Plan Empresarial Pro',
                'administrative_status' => LicenseAdministrativeStatus::Active,
                'starts_at' => now()->subDays(5),
                'expires_at' => now()->addDays(60),
                'grace_period_days' => 7,
                'max_users' => 15,
                'max_branches' => 3,
            ]
        );
        $licenseA->modules()->sync(Module::pluck('id')->all());

        // Empresa 2: Pacific Retail S.A.C. (Licencia Vigente)
        $companyB = Company::updateOrCreate(
            ['slug' => 'pacific-retail'],
            [
                'legal_name' => 'Pacific Retail S.A.C.',
                'trade_name' => 'Pacific Retail',
                'tax_id' => '20609876543',
                'tax_id_type' => 'RUC',
                'contact_email' => 'contacto@pacific-retail.pe',
                'country_code' => 'PE',
                'administrative_status' => CompanyStatus::Active,
            ]
        );

        $licenseB = License::updateOrCreate(
            ['company_id' => $companyB->id],
            [
                'license_key' => 'REC-DEMO-PACIFIC-2026',
                'plan_name' => 'Plan Básico',
                'administrative_status' => LicenseAdministrativeStatus::Active,
                'starts_at' => now()->subDays(5),
                'expires_at' => now()->addDays(60),
                'grace_period_days' => 5,
                'max_users' => 5,
                'max_branches' => 1,
            ]
        );
        $licenseB->modules()->sync(Module::whereIn('slug', ['core_commercial', 'sales', 'inventory'])->pluck('id')->all());

        // Empresa 3: Gracia Retail S.A.C. (Licencia en Período de Gracia / Solo Lectura)
        $companyGracia = Company::updateOrCreate(
            ['slug' => 'gracia-store'],
            [
                'legal_name' => 'Gracia Retail S.A.C.',
                'trade_name' => 'Gracia Retail',
                'tax_id' => '20605555555',
                'tax_id_type' => 'RUC',
                'contact_email' => 'contacto@gracia-retail.pe',
                'country_code' => 'PE',
                'administrative_status' => CompanyStatus::Active,
            ]
        );

        $licenseGracia = License::updateOrCreate(
            ['company_id' => $companyGracia->id],
            [
                'license_key' => 'REC-DEMO-GRACIA-2026',
                'plan_name' => 'Plan Estándar',
                'administrative_status' => LicenseAdministrativeStatus::Active,
                'starts_at' => now()->subDays(35),
                'expires_at' => now()->subDays(2),
                'grace_period_days' => 7,
                'max_users' => 5,
                'max_branches' => 1,
            ]
        );
        $licenseGracia->modules()->sync(Module::pluck('id')->all());

        // Empresa 4: Vencida Corp S.A.C. (Licencia Vencida / Expirada)
        $companyVencida = Company::updateOrCreate(
            ['slug' => 'vencida-store'],
            [
                'legal_name' => 'Vencida Corp S.A.C.',
                'trade_name' => 'Vencida Corp',
                'tax_id' => '20604444444',
                'tax_id_type' => 'RUC',
                'contact_email' => 'contacto@vencida-corp.pe',
                'country_code' => 'PE',
                'administrative_status' => CompanyStatus::Active,
            ]
        );

        $licenseVencida = License::updateOrCreate(
            ['company_id' => $companyVencida->id],
            [
                'license_key' => 'REC-DEMO-VENCIDA-2026',
                'plan_name' => 'Plan Estándar',
                'administrative_status' => LicenseAdministrativeStatus::Active,
                'starts_at' => now()->subDays(60),
                'expires_at' => now()->subDays(20),
                'effective_ends_at' => now()->subDays(15),
                'grace_period_days' => 5,
                'max_users' => 5,
                'max_branches' => 1,
            ]
        );
        $licenseVencida->modules()->sync(Module::pluck('id')->all());

        // 3. Instanciación de Roles Empresariales
        $this->info('3. Instanciando roles empresariales...');

        $allCompanyPerms = Permission::where('scope', 'company')->get()->keyBy('slug');

        // Roles en Andina Store
        $ownerRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'company_owner'],
            ['name' => 'Propietario de Empresa', 'description' => 'Control total sobre la empresa y módulos', 'is_system' => true]
        );
        $ownerRoleA->permissions()->sync($allCompanyPerms->pluck('id')->all());

        $adminRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'company_admin'],
            ['name' => 'Administrador Comercial', 'description' => 'Administración operativa y gestión de miembros', 'is_system' => true]
        );
        $adminRoleA->permissions()->sync($allCompanyPerms->whereIn('slug', [
            'commercial.company.view', 'commercial.company.update',
            'commercial.members.view', 'commercial.members.invite', 'commercial.members.assign_roles',
            'commercial.members.suspend', 'commercial.members.reactivate', 'commercial.members.revoke',
            'commercial.roles.view', 'commercial.roles.create', 'commercial.roles.update',
            'commercial.roles.assign_permissions', 'commercial.roles.delete',
            'inventory.products.view', 'inventory.products.manage', 'inventory.stock.view', 'inventory.stock.adjust',
            'purchases.orders.view', 'purchases.orders.manage', 'sales.orders.view', 'sales.orders.create',
            'sales.orders.cancel', 'sales.customers.manage', 'billing.invoices.view', 'billing.invoices.issue',
            'billing.invoices.cancel', 'reports.sales.view', 'reports.inventory.view',
        ])->pluck('id')->all());

        // Rol limitado en Andina Store: Administrador Limitado (sin invitar, suspender ni crear roles)
        $limitedAdminRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'limited_admin'],
            ['name' => 'Administrador Limitado', 'description' => 'Solo consulta de miembros y roles sin facultades de delegación', 'is_system' => false]
        );
        $limitedAdminRoleA->permissions()->sync($allCompanyPerms->whereIn('slug', [
            'commercial.company.view', 'commercial.members.view', 'commercial.roles.view',
        ])->pluck('id')->all());

        $cashierRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'sales_cashier'],
            ['name' => 'Cajero / Vendedor', 'description' => 'Emisión de ventas, cotizaciones y consulta de stock', 'is_system' => false]
        );
        $cashierRoleA->permissions()->sync($allCompanyPerms->whereIn('slug', [
            'commercial.company.view', 'inventory.products.view', 'inventory.stock.view',
            'sales.orders.view', 'sales.orders.create', 'sales.customers.manage',
            'billing.invoices.view', 'billing.invoices.issue',
        ])->pluck('id')->all());

        $warehouseRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'warehouse_keeper'],
            ['name' => 'Almacenero / Logística', 'description' => 'Gestión de existencias y almacenes', 'is_system' => false]
        );
        $warehouseRoleA->permissions()->sync($allCompanyPerms->whereIn('slug', [
            'commercial.company.view', 'inventory.products.view', 'inventory.stock.view',
            'inventory.stock.adjust', 'purchases.orders.view',
        ])->pluck('id')->all());

        // Rol personalizado en Andina Store: Auditor de Tienda
        $auditorRoleA = CompanyRole::updateOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'store_auditor'],
            ['name' => 'Auditor de Tienda (Personalizado)', 'description' => 'Inspección de reportes y catálogo en solo lectura', 'is_system' => false]
        );
        $auditorRoleA->permissions()->sync($allCompanyPerms->whereIn('slug', [
            'commercial.company.view', 'inventory.products.view', 'sales.orders.view',
            'reports.sales.view', 'reports.inventory.view',
        ])->pluck('id')->all());

        // Roles en Pacific Retail
        $ownerRoleB = CompanyRole::updateOrCreate(
            ['company_id' => $companyB->id, 'slug' => 'company_owner'],
            ['name' => 'Propietario de Empresa', 'description' => 'Control total sobre la empresa y módulos', 'is_system' => true]
        );
        $ownerRoleB->permissions()->sync($allCompanyPerms->pluck('id')->all());

        $adminRoleB = CompanyRole::updateOrCreate(
            ['company_id' => $companyB->id, 'slug' => 'company_admin'],
            ['name' => 'Administrador Comercial', 'description' => 'Administración operativa', 'is_system' => true]
        );
        $adminRoleB->permissions()->sync($adminRoleA->permissions()->pluck('id')->all());

        $cashierRoleB = CompanyRole::updateOrCreate(
            ['company_id' => $companyB->id, 'slug' => 'sales_cashier'],
            ['name' => 'Cajero / Vendedor', 'description' => 'Ventas y catálogo', 'is_system' => false]
        );
        $cashierRoleB->permissions()->sync($cashierRoleA->permissions()->pluck('id')->all());

        // Roles en Gracia Retail
        $ownerRoleGracia = CompanyRole::updateOrCreate(
            ['company_id' => $companyGracia->id, 'slug' => 'company_owner'],
            ['name' => 'Propietario de Empresa', 'description' => 'Control total sobre la empresa y módulos', 'is_system' => true]
        );
        $ownerRoleGracia->permissions()->sync($allCompanyPerms->pluck('id')->all());

        $adminRoleGracia = CompanyRole::updateOrCreate(
            ['company_id' => $companyGracia->id, 'slug' => 'company_admin'],
            ['name' => 'Administrador Comercial', 'description' => 'Administración operativa', 'is_system' => true]
        );
        $adminRoleGracia->permissions()->sync($adminRoleA->permissions()->pluck('id')->all());

        // Roles en Vencida Corp
        $ownerRoleVencida = CompanyRole::updateOrCreate(
            ['company_id' => $companyVencida->id, 'slug' => 'company_owner'],
            ['name' => 'Propietario de Empresa', 'description' => 'Control total sobre la empresa y módulos', 'is_system' => true]
        );
        $ownerRoleVencida->permissions()->sync($allCompanyPerms->pluck('id')->all());

        $adminRoleVencida = CompanyRole::updateOrCreate(
            ['company_id' => $companyVencida->id, 'slug' => 'company_admin'],
            ['name' => 'Administrador Comercial', 'description' => 'Administración operativa', 'is_system' => true]
        );
        $adminRoleVencida->permissions()->sync($adminRoleA->permissions()->pluck('id')->all());

        // 4. Creación y Asignación de Cuentas de Demostración
        $this->info('4. Creando usuarios de demostración...');

        $platformSuperAdminRole = PlatformRole::where('slug', 'super_admin')->firstOrFail();
        $platformSupportRole = PlatformRole::where('slug', 'support_agent')->firstOrFail();
        $platformSalesRole = PlatformRole::where('slug', 'sales_operator')->firstOrFail();

        $accounts = [];

        // --- A. Roles de Plataforma SaaS ---
        // 1. Superadministrador SaaS
        $uSaasAdmin = User::updateOrCreate(
            ['email' => 'saas-admin@demo.local'],
            [
                'name' => 'Operador SaaS (Superadmin)',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $uSaasAdmin->platformRoles()->sync([
            $platformSuperAdminRole->id => ['assigned_at' => now(), 'suspended_at' => null],
        ]);
        $accounts[] = ['saas-admin@demo.local', 'Operador SaaS (Superadmin)', 'Activo', 'super_admin', 'Ninguna', 'N/A', 'N/A', 'Control integral de plataforma SaaS (/platform/staff)'];

        // 2. Agente de Soporte Técnico SaaS
        $uSupport = User::updateOrCreate(
            ['email' => 'support-agent@demo.local'],
            [
                'name' => 'Agente Soporte SaaS',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $uSupport->platformRoles()->sync([
            $platformSupportRole->id => ['assigned_at' => now(), 'suspended_at' => null],
        ]);
        $accounts[] = ['support-agent@demo.local', 'Agente Soporte SaaS', 'Activo', 'support_agent', 'Ninguna', 'N/A', 'N/A', 'Soporte, diagnóstico y auditoría central (solo consulta técnica)'];

        // 3. Operador Comercial SaaS
        $uSalesOp = User::updateOrCreate(
            ['email' => 'sales-operator@demo.local'],
            [
                'name' => 'Operador Comercial SaaS',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $uSalesOp->platformRoles()->sync([
            $platformSalesRole->id => ['assigned_at' => now(), 'suspended_at' => null],
        ]);
        $accounts[] = ['sales-operator@demo.local', 'Operador Comercial SaaS', 'Activo', 'sales_operator', 'Ninguna', 'N/A', 'N/A', 'Gestión comercial de empresas y licencias SaaS'];

        // 4. Usuario Híbrido (SaaS + Empresa)
        $uHybrid = User::updateOrCreate(
            ['email' => 'saas-hybrid@demo.local'],
            ['name' => 'Usuario Híbrido (SaaS + ERP)', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $uHybrid->platformRoles()->sync([
            $platformSupportRole->id => ['assigned_at' => now(), 'suspended_at' => null],
        ]);
        $memHybrid = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uHybrid->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memHybrid->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['saas-hybrid@demo.local', 'Usuario Híbrido (SaaS + ERP)', 'Activo', 'support_agent', 'andina-store', 'Activa', 'sales_cashier', 'Soporte en SaaS + Cajero en Andina (sin privilegio implícito)'];

        // --- B. Administradores por Empresa ---
        // 5. Admin Andina Store
        $uAdminA = User::updateOrCreate(
            ['email' => 'admin-andina@demo.local'],
            [
                'name' => 'Admin Andina Store',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memAdminA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uAdminA->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memAdminA->syncRoles([$adminRoleA->id]);
        $accounts[] = ['admin-andina@demo.local', 'Admin Andina Store', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'company_admin', 'Administración de miembros y roles en Andina Store'];

        // 6. Admin Limitado Andina Store (Prueba de límite de delegación)
        $uLimitedAdmin = User::updateOrCreate(
            ['email' => 'limited-admin@demo.local'],
            [
                'name' => 'Admin Limitado Andina',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memLimitedAdmin = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uLimitedAdmin->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memLimitedAdmin->syncRoles([$limitedAdminRoleA->id]);
        $accounts[] = ['limited-admin@demo.local', 'Admin Limitado Andina', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'limited_admin', 'Consulta miembros/roles; bloqueado a invitar, suspender o ampliar roles'];

        // 7. Admin con 2FA pendiente (Prueba de intercepción obligatoria de 2FA)
        $uPending2fa = User::updateOrCreate(
            ['email' => 'pending-2fa-admin@demo.local'],
            [
                'name' => 'Admin Sin 2FA Configurado',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
            ]
        );
        $memPending2fa = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uPending2fa->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memPending2fa->syncRoles([$adminRoleA->id]);
        $accounts[] = ['pending-2fa-admin@demo.local', 'Admin Sin 2FA Configurado', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'company_admin', 'Privilegiado sin 2FA: interceptado hacia /settings/security al entrar'];

        // 8. Admin Pacific Retail
        $uAdminB = User::updateOrCreate(
            ['email' => 'admin-pacific@demo.local'],
            ['name' => 'Admin Pacific Retail', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memAdminB = Membership::updateOrCreate(
            ['company_id' => $companyB->id, 'user_id' => $uAdminB->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memAdminB->syncRoles([$adminRoleB->id]);
        $accounts[] = ['admin-pacific@demo.local', 'Admin Pacific Retail', 'Activo', 'Ninguno', 'pacific-retail', 'Activa', 'company_admin', 'Administración en Pacific Retail (aislado de Andina)'];

        // 9. Admin Gracia Retail (Licencia en Gracia)
        $uAdminGracia = User::updateOrCreate(
            ['email' => 'admin-gracia@demo.local'],
            ['name' => 'Admin Gracia Retail', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memAdminGracia = Membership::updateOrCreate(
            ['company_id' => $companyGracia->id, 'user_id' => $uAdminGracia->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memAdminGracia->syncRoles([$adminRoleGracia->id]);
        $accounts[] = ['admin-gracia@demo.local', 'Admin Gracia Retail', 'Activo', 'Ninguno', 'gracia-store', 'Activa', 'company_admin', 'Gestión en Gracia: comercial solo lectura, seguridad permitida'];

        // 10. Admin Vencida Corp (Licencia Vencida)
        $uAdminVencida = User::updateOrCreate(
            ['email' => 'admin-vencida@demo.local'],
            ['name' => 'Admin Vencida Corp', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memAdminVencida = Membership::updateOrCreate(
            ['company_id' => $companyVencida->id, 'user_id' => $uAdminVencida->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memAdminVencida->syncRoles([$adminRoleVencida->id]);
        $accounts[] = ['admin-vencida@demo.local', 'Admin Vencida Corp', 'Activo', 'Ninguno', 'vencida-store', 'Activa', 'company_admin', 'Gestión en Vencida: seguridad permitida, comercial 403, altas bloqueadas'];

        // --- C. Propietarios Exclusivos de Empresas (is_owner = true) ---
        // 11. Propietario Andina Store
        $uOwnerA = User::updateOrCreate(
            ['email' => 'owner-andina@demo.local'],
            [
                'name' => 'Propietario Andina Store',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memOwnerA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uOwnerA->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => true]
        );
        $memOwnerA->syncRoles([$ownerRoleA->id]);
        $accounts[] = ['owner-andina@demo.local', 'Propietario Andina Store', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'company_owner', 'Titular exclusivo de Andina Store (is_owner = true, puede transferir)'];

        // 12. Propietario Pacific Retail
        $uOwnerB = User::updateOrCreate(
            ['email' => 'owner-pacific@demo.local'],
            [
                'name' => 'Propietario Pacific Retail',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memOwnerB = Membership::updateOrCreate(
            ['company_id' => $companyB->id, 'user_id' => $uOwnerB->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => true]
        );
        $memOwnerB->syncRoles([$ownerRoleB->id]);
        $accounts[] = ['owner-pacific@demo.local', 'Propietario Pacific Retail', 'Activo', 'Ninguno', 'pacific-retail', 'Activa', 'company_owner', 'Titular exclusivo de Pacific Retail (is_owner = true)'];

        // 13. Propietario Gracia Retail
        $uOwnerGracia = User::updateOrCreate(
            ['email' => 'owner-gracia@demo.local'],
            [
                'name' => 'Propietario Gracia Retail',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memOwnerGracia = Membership::updateOrCreate(
            ['company_id' => $companyGracia->id, 'user_id' => $uOwnerGracia->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => true]
        );
        $memOwnerGracia->syncRoles([$ownerRoleGracia->id]);
        $accounts[] = ['owner-gracia@demo.local', 'Propietario Gracia Retail', 'Activo', 'Ninguno', 'gracia-store', 'Activa', 'company_owner', 'Titular exclusivo de Gracia Retail (is_owner = true)'];

        // 14. Propietario Vencida Corp
        $uOwnerVencida = User::updateOrCreate(
            ['email' => 'owner-vencida@demo.local'],
            [
                'name' => 'Propietario Vencida Corp',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memOwnerVencida = Membership::updateOrCreate(
            ['company_id' => $companyVencida->id, 'user_id' => $uOwnerVencida->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => true]
        );
        $memOwnerVencida->syncRoles([$ownerRoleVencida->id]);
        $accounts[] = ['owner-vencida@demo.local', 'Propietario Vencida Corp', 'Activo', 'Ninguno', 'vencida-store', 'Activa', 'company_owner', 'Titular exclusivo de Vencida Corp (is_owner = true)'];

        // 15. Miembro Candidato para Transferencia de Titularidad (Verificado y con 2FA)
        $uTransferCandidate = User::updateOrCreate(
            ['email' => 'transfer-candidate@demo.local'],
            [
                'name' => 'Candidato a Titularidad',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $memTransferCandidate = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uTransferCandidate->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now(), 'is_owner' => false]
        );
        $memTransferCandidate->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['transfer-candidate@demo.local', 'Candidato a Titularidad', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'sales_cashier', 'Miembro activo, verificado y con 2FA apto para asumir titularidad'];

        // --- D. Roles Operativos y Plantillas en Andina Store ---

        // 10. Cajero / Vendedor (Plantilla sales_cashier)
        $uCashierA = User::updateOrCreate(
            ['email' => 'seller-andina@demo.local'],
            ['name' => 'Cajero Andina Store', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memCashierA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uCashierA->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memCashierA->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['seller-andina@demo.local', 'Cajero Andina Store', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'sales_cashier', 'Ventas y catálogo. Bloqueado a administración de miembros'];

        // 11. Almacenero / Logística (Plantilla warehouse_keeper)
        $uWarehouseA = User::updateOrCreate(
            ['email' => 'warehouse-andina@demo.local'],
            ['name' => 'Almacenero Andina Store', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memWarehouseA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uWarehouseA->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memWarehouseA->syncRoles([$warehouseRoleA->id]);
        $accounts[] = ['warehouse-andina@demo.local', 'Almacenero Andina Store', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'warehouse_keeper', 'Stock y almacenes. Bloqueado a ventas y administración'];

        // 12. Auditor de Tienda (Rol Personalizado)
        $uAuditorA = User::updateOrCreate(
            ['email' => 'auditor-andina@demo.local'],
            ['name' => 'Auditor Andina Store (Custom)', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memAuditorA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uAuditorA->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memAuditorA->syncRoles([$auditorRoleA->id]);
        $accounts[] = ['auditor-andina@demo.local', 'Auditor Andina Store (Custom)', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'store_auditor', 'Reportes y catálogo en solo lectura'];

        // --- D. Escenarios Multi-Empresa, Suspensión y Cierre ---
        // 13. Usuario Multi-Empresa (Admin en A / Cajero en B)
        $uDual = User::updateOrCreate(
            ['email' => 'dual-user@demo.local'],
            ['name' => 'Usuario Multi-Empresa', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memDualA = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uDual->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memDualA->syncRoles([$adminRoleA->id]);

        $memDualB = Membership::updateOrCreate(
            ['company_id' => $companyB->id, 'user_id' => $uDual->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memDualB->syncRoles([$cashierRoleB->id]);
        $accounts[] = ['dual-user@demo.local', 'Usuario Multi-Empresa', 'Activo', 'Ninguno', 'andina y pacific', 'Activa', 'admin (A) / cashier (B)', 'Admin en Andina / Cajero limitado en Pacific'];

        // 14. Usuario sin Membresías
        $uUnassigned = User::updateOrCreate(
            ['email' => 'unassigned@demo.local'],
            ['name' => 'Usuario Sin Membresías', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $accounts[] = ['unassigned@demo.local', 'Usuario Sin Membresías', 'Activo', 'Ninguno', 'Ninguna', 'N/A', 'N/A', 'Espacio personal aislado; sin acceso a empresas ni SaaS'];

        // 15. Cuenta Global Suspendida
        $uSuspendedAcc = User::updateOrCreate(
            ['email' => 'suspended-account@demo.local'],
            ['name' => 'Cuenta Global Suspendida', 'password' => $hashedPassword, 'status' => UserStatus::Suspended, 'email_verified_at' => now()]
        );
        $memSuspAcc = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uSuspendedAcc->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $memSuspAcc->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['suspended-account@demo.local', 'Cuenta Global Suspendida', 'Suspendido', 'Ninguno', 'andina-store', 'Activa', 'sales_cashier', 'Bloqueo total inmediato al autenticar (EnsureUserIsActive)'];

        // 16. Membresía Suspendida (Cuenta Global Activa)
        $uSuspendedMem = User::updateOrCreate(
            ['email' => 'suspended-member@demo.local'],
            ['name' => 'Membresía Suspendida', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memSuspended = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uSuspendedMem->id],
            ['status' => MembershipStatus::Suspended, 'joined_at' => now()]
        );
        $memSuspended->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['suspended-member@demo.local', 'Membresía Suspendida', 'Activo', 'Ninguno', 'andina-store', 'Suspendida', 'sales_cashier', 'Login personal permitido; acceso a Andina bloqueado (403)'];

        // 17. Membresía Revocada
        $uRevokedMem = User::updateOrCreate(
            ['email' => 'revoked-member@demo.local'],
            ['name' => 'Membresía Revocada', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => now()]
        );
        $memRevoked = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $uRevokedMem->id],
            ['status' => MembershipStatus::Revoked, 'joined_at' => now()]
        );
        $memRevoked->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['revoked-member@demo.local', 'Membresía Revocada', 'Activo', 'Ninguno', 'andina-store', 'Revocada', 'sales_cashier', 'Histórico en base conservado; acceso revocado (403)'];

        // 18. Usuario sin Correo Verificado
        $uUnverified = User::updateOrCreate(
            ['email' => 'unverified-user@demo.local'],
            ['name' => 'Usuario No Verificado', 'password' => $hashedPassword, 'status' => UserStatus::Active, 'email_verified_at' => null]
        );
        $accounts[] = ['unverified-user@demo.local', 'Usuario No Verificado', 'Activo', 'Ninguno', 'Ninguna', 'N/A', 'N/A', 'Login permitido; redirige a /email/verify'];

        // 19. Usuario con 2FA Activo
        $u2fa = User::updateOrCreate(
            ['email' => 'two-factor-user@demo.local'],
            [
                'name' => 'Usuario con 2FA Activo',
                'password' => $hashedPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
                'two_factor_confirmed_at' => now(),
            ]
        );
        $mem2fa = Membership::updateOrCreate(
            ['company_id' => $companyA->id, 'user_id' => $u2fa->id],
            ['status' => MembershipStatus::Active, 'joined_at' => now()]
        );
        $mem2fa->syncRoles([$cashierRoleA->id]);
        $accounts[] = ['two-factor-user@demo.local', 'Usuario con 2FA Activo', 'Activo', 'Ninguno', 'andina-store', 'Activa', 'sales_cashier', 'Exige desafío TOTP (/two-factor-challenge) o código de recuperación'];

        $this->newLine();
        $this->info('Ambiente de demostración preparado exitosamente.');
        $this->info("Contraseña asignada a las cuentas demo: {$password}");
        $this->warn('ADVERTENCIA: Esta contraseña se muestra exclusivamente en esta consola local para desarrollo y pruebas.');

        $this->table(
            ['Correo', 'Nombre', 'Estado', 'Rol SaaS', 'Empresa', 'Estado Memb.', 'Roles ERP', 'Acceso Esperado'],
            $accounts
        );

        return self::SUCCESS;
    }
}
