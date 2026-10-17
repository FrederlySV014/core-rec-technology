<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
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
use App\Modules\Provisioning\Enums\ProvisioningStatus;
use App\Modules\Provisioning\Services\TenantProvisioningService;
use App\Modules\Tenancy\Exceptions\TenantContextException;
use App\Modules\Tenancy\Models\TenantIdentity;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Tenant Context and Commercial Database Connection per Request (Phase 6)', function () {
    $cleanupCompanies = [];

    afterEach(function () use (&$cleanupCompanies) {
        $provisioner = app(TenantProvisioningService::class);
        $manager = app(TenantConnectionManager::class);
        $manager->disconnect();

        foreach ($cleanupCompanies as $company) {
            try {
                $provisioner->dropTenantDatabase($company);
                $company->forceDelete();
            } catch (Throwable) {
                // Ignore teardown drops
            }
        }
        $cleanupCompanies = [];
    });

    function setupTenantModuleAndPermissions(): array
    {
        $inventoryModule = Module::firstOrCreate(
            ['slug' => 'inventory'],
            ['name' => 'Inventario y Almacenes', 'is_core' => false, 'status' => 'active']
        );

        $viewPerm = Permission::firstOrCreate(
            ['slug' => 'inventory.products.view'],
            ['scope' => PermissionScope::Company, 'module_id' => $inventoryModule->id, 'name' => 'Ver Productos']
        );

        $membersViewPerm = Permission::firstOrCreate(
            ['slug' => 'commercial.members.view'],
            ['scope' => PermissionScope::Company, 'name' => 'Ver Miembros']
        );

        $rolesViewPerm = Permission::firstOrCreate(
            ['slug' => 'commercial.roles.view'],
            ['scope' => PermissionScope::Company, 'name' => 'Ver Roles']
        );

        return [
            'module' => $inventoryModule,
            'viewPerm' => $viewPerm,
            'membersViewPerm' => $membersViewPerm,
            'rolesViewPerm' => $rolesViewPerm,
        ];
    }

    function createProvisionedTestCompany(string $suffix, array &$cleanupList, bool $provision = true): array
    {
        $perms = setupTenantModuleAndPermissions();

        $unique = Str::lower(Str::random(6));
        $company = Company::create([
            'legal_name' => "Tenant Co {$suffix} {$unique} S.A.C.",
            'trade_name' => "Tenant {$suffix}",
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20'.rand(100000000, 999999999),
            'slug' => "tenant-{$suffix}-{$unique}",
            'contact_email' => "tenant.{$suffix}.{$unique}@example.com",
            'administrative_status' => CompanyStatus::Active,
            'technical_provisioning_status' => ProvisioningStatus::Pending,
        ]);

        $cleanupList[] = $company;

        $license = License::create([
            'company_id' => $company->id,
            'license_key' => 'LIC-'.Str::upper(Str::random(12)),
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
            'name' => "Operador {$suffix}",
            'slug' => "operador-{$suffix}-{$unique}",
        ]);
        $role->permissions()->sync([
            $perms['viewPerm']->id,
            $perms['membersViewPerm']->id,
            $perms['rolesViewPerm']->id,
        ]);

        if ($provision) {
            $provisioner = app(TenantProvisioningService::class);
            $provisioner->provision($company);
        }

        return [
            'company' => $company,
            'license' => $license,
            'role' => $role,
        ];
    }

    function createActiveUser(): User
    {
        return User::factory()->create([
            'status' => UserStatus::Active,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    function assignMembership(Company $company, User $user, CompanyRole $role): Membership
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

    test('1. an authorized user in Company A queries Company A identity', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('alpha', $cleanupCompanies);
        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);

        $response = $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity");

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('company_id', $setupA['company']->id)
            ->assertJsonPath('tax_id', $setupA['company']->tax_id)
            ->assertJsonPath('is_connection_open', true);

        // After the response cycle terminates, the connection must be closed and released
        $manager = app(TenantConnectionManager::class);
        expect($manager->isConnectionOpen())->toBeFalse()
            ->and($manager->getActiveCompany())->toBeNull();
    });

    test('2. the same authorized user in Company B queries Company B identity', function () use (&$cleanupCompanies) {
        $setupB = createProvisionedTestCompany('beta', $cleanupCompanies);
        $user = createActiveUser();
        assignMembership($setupB['company'], $user, $setupB['role']);

        $response = $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/app/{$setupB['company']->slug}/diagnostic/tenant-identity");

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('company_id', $setupB['company']->id)
            ->assertJsonPath('tax_id', $setupB['company']->tax_id);

        $manager = app(TenantConnectionManager::class);
        expect($manager->isConnectionOpen())->toBeFalse();
    });

    test('3. sequence A -> B -> A executes in the same process without cross-contamination', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('seq-a', $cleanupCompanies);
        $setupB = createProvisionedTestCompany('seq-b', $cleanupCompanies);

        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);
        assignMembership($setupB['company'], $user, $setupB['role']);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // Step 1: Request to Company A
        $resA1 = $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity");
        $resA1->assertOk()->assertJsonPath('company_id', $setupA['company']->id);

        // Step 2: Request to Company B in the exact same process
        $resB = $this->getJson("/app/{$setupB['company']->slug}/diagnostic/tenant-identity");
        $resB->assertOk()->assertJsonPath('company_id', $setupB['company']->id);

        // Step 3: Request back to Company A in the exact same process
        $resA2 = $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity");
        $resA2->assertOk()->assertJsonPath('company_id', $setupA['company']->id);

        $manager = app(TenantConnectionManager::class);
        expect($manager->getActiveCompany())->toBeNull()
            ->and($manager->isConnectionOpen())->toBeFalse();
    });

    test('4. two tabs with different companies do not rely on global session company state', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('tab-a', $cleanupCompanies);
        $setupB = createProvisionedTestCompany('tab-b', $cleanupCompanies);

        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);
        assignMembership($setupB['company'], $user, $setupB['role']);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // Simulate Tab 1 visiting Company A
        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")
            ->assertOk()
            ->assertJsonPath('company_id', $setupA['company']->id);

        // Assert session does NOT hold any active_company_id or tenant_connection keys
        expect(session()->has('active_company_id'))->toBeFalse()
            ->and(session()->has('tenant_id'))->toBeFalse()
            ->and(session()->has('current_company'))->toBeFalse();

        // Simulate Tab 2 visiting Company B
        $this->getJson("/app/{$setupB['company']->slug}/diagnostic/tenant-identity")
            ->assertOk()
            ->assertJsonPath('company_id', $setupB['company']->id);

        expect(session()->has('active_company_id'))->toBeFalse();
    });

    test('5. a user without membership in Company B is rejected before establishing any connection', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('mem-a', $cleanupCompanies);
        $setupB = createProvisionedTestCompany('mem-b', $cleanupCompanies);

        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);
        // User has NO membership in Company B

        // Purge connections before request
        DB::purge('tenant');

        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/app/{$setupB['company']->slug}/diagnostic/tenant-identity")
            ->assertNotFound(); // 404 to avoid leaking company existence

        // Prove that no tenant connection was opened
        $connections = DB::getConnections();
        expect(array_key_exists('tenant', $connections))->toBeFalse();
        expect(app(TenantConnectionManager::class)->isConnectionOpen())->toBeFalse();
    });

    test('6. a SaaS platform operator without company membership gets no commercial access', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('saas-op', $cleanupCompanies);

        $viewPerm = Permission::firstOrCreate(
            ['slug' => 'platform.companies.view'],
            ['scope' => PermissionScope::Platform, 'name' => 'Ver Empresas']
        );
        $platformRole = PlatformRole::firstOrCreate(
            ['slug' => 'saas_inspector'],
            ['name' => 'Inspector SaaS']
        );
        $platformRole->permissions()->syncWithoutDetaching([$viewPerm->id]);

        $operator = createActiveUser();
        $operator->platformRoles()->sync([$platformRole->id]);
        // Operator has NO membership in Company A

        DB::purge('tenant');

        $this->actingAs($operator)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")
            ->assertNotFound();

        $connections = DB::getConnections();
        expect(array_key_exists('tenant', $connections))->toBeFalse();
    });

    test('7. suspending membership or revoking permission is immediately reflected on the next request', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('susp-mem', $cleanupCompanies);
        $user = createActiveUser();
        $membership = assignMembership($setupA['company'], $user, $setupA['role']);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // Request 1: Active membership succeeds
        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")->assertOk();

        // Suspend membership centrally
        $membership->update(['status' => MembershipStatus::Suspended]);

        // Request 2: Must immediately be rejected without opening tenant connection
        DB::purge('tenant');
        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")->assertNotFound();

        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();
    });

    test('8. existing license and module restrictions prevent commercial connection', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('lic-restr', $cleanupCompanies);
        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // Request 1 succeeds
        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")->assertOk();

        // Case A: License suspended
        $setupA['license']->update(['administrative_status' => LicenseAdministrativeStatus::Suspended]);
        DB::purge('tenant');

        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")->assertForbidden();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();

        // Restore license but detach module
        $setupA['license']->update(['administrative_status' => LicenseAdministrativeStatus::Active]);
        $setupA['license']->modules()->detach();
        DB::purge('tenant');

        $this->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")->assertForbidden();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();
    });

    test('9. an unprovisioned database blocks commercial access while preserving central administration', function () use (&$cleanupCompanies) {
        // Create company WITHOUT provisioning the commercial database
        $setupUnprov = createProvisionedTestCompany('unprov', $cleanupCompanies, provision: false);
        $user = createActiveUser();
        assignMembership($setupUnprov['company'], $user, $setupUnprov['role']);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // 1. Commercial route requiring tenant connection must be blocked with 503
        $response = $this->getJson("/app/{$setupUnprov['company']->slug}/diagnostic/tenant-identity");
        $response->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'La base de datos comercial de la empresa no se encuentra disponible o aún está en proceso de aprovisionamiento.');

        // 2. Central operations for members and roles MUST remain accessible
        $this->get("/app/{$setupUnprov['company']->slug}/members")->assertOk();
        $this->get("/app/{$setupUnprov['company']->slug}/roles")->assertOk();
        $this->get("/app/{$setupUnprov['company']->slug}")->assertOk();
    });

    test('10. connection pointing to a mismatched company database is rejected upon tenant_identity verification', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('target-a', $cleanupCompanies);
        $setupD = createProvisionedTestCompany('mismatch-d', $cleanupCompanies);

        $user = createActiveUser();
        assignMembership($setupD['company'], $user, $setupD['role']);

        // Tamper tenant_identity in Company D's database to point to another company ID
        $connD = $setupD['company']->fresh()->databaseConnection;
        $provisioner = app(TenantProvisioningService::class);
        $adminMethod = new ReflectionMethod($provisioner, 'getDatabaseAdminPdo');
        $adminMethod->setAccessible(true);
        /** @var PDO $dbPdo */
        $dbPdo = $adminMethod->invoke($provisioner, $connD->database);
        $dbPdo->exec(sprintf('UPDATE tenant_identity SET company_id = %d', $setupA['company']->id));

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // Request must be aborted with 500 Identity Mismatch, without leaking secrets
        $response = $this->getJson("/app/{$setupD['company']->slug}/diagnostic/tenant-identity");
        $response->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Inconsistencia crítica de identidad en la base de datos comercial asignada. La conexión ha sido revocada inmediatamente por seguridad.');

        // Connection must be immediately revoked and purged
        $manager = app(TenantConnectionManager::class);
        expect($manager->isConnectionOpen())->toBeFalse()
            ->and($manager->getActiveCompany())->toBeNull();
    });

    test('11. after a connection failure or exception, another company request succeeds normally', function () use (&$cleanupCompanies) {
        $setupBroken = createProvisionedTestCompany('broken-conn', $cleanupCompanies);
        $setupHealthy = createProvisionedTestCompany('healthy-conn', $cleanupCompanies);

        $user = createActiveUser();
        assignMembership($setupBroken['company'], $user, $setupBroken['role']);
        assignMembership($setupHealthy['company'], $user, $setupHealthy['role']);

        // Point broken company to non-routable host to induce connection failure
        $setupBroken['company']->fresh()->databaseConnection->update([
            'host' => '192.0.2.1', // TEST-NET non-routable IP
            'port' => 65432,
        ]);

        $this->actingAs($user)->withSession(['auth.2fa_passed' => true]);

        // 1. Broken connection fails with 503
        $resBroken = $this->getJson("/app/{$setupBroken['company']->slug}/diagnostic/tenant-identity");
        $resBroken->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'No fue posible establecer conexión con la base de datos comercial. Por favor intente más tarde.');

        // 2. Immediately afterwards, healthy company request succeeds with 200 OK
        $resHealthy = $this->getJson("/app/{$setupHealthy['company']->slug}/diagnostic/tenant-identity");
        $resHealthy->assertOk()
            ->assertJsonPath('company_id', $setupHealthy['company']->id);
    });

    test('12. commercial query without active context cannot execute and does not fallback to central', function () {
        $manager = app(TenantConnectionManager::class);
        $manager->disconnect();

        expect($manager->hasActiveTenant())->toBeFalse();

        // Attempting to query TenantIdentity model without active tenant context must throw controlled TenantContextException
        expect(fn () => TenantIdentity::first())
            ->toThrow(TenantContextException::class, 'Operación comercial no permitida: no hay un contexto empresarial activo');

        // Confirm default connection remains 'central'
        expect(Config::get('database.default'))->toBe('central');
    });

    test('13. login, security settings, platform administration and member management execute without tenant connections', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('central-pure', $cleanupCompanies);
        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);

        DB::purge('tenant');

        // 1. Visit dashboard
        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->get('/dashboard')
            ->assertOk();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();

        // 2. Visit central workspace
        $this->get("/app/{$setupA['company']->slug}")->assertOk();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();

        // 3. Visit central members list
        $this->get("/app/{$setupA['company']->slug}/members")->assertOk();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();

        // 4. Visit central roles list
        $this->get("/app/{$setupA['company']->slug}/roles")->assertOk();
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();
    });

    test('14. connections, context and sensitive configuration are completely blanked upon completion', function () use (&$cleanupCompanies) {
        $setupA = createProvisionedTestCompany('clean-leak', $cleanupCompanies);
        $user = createActiveUser();
        assignMembership($setupA['company'], $user, $setupA['role']);

        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson("/app/{$setupA['company']->slug}/diagnostic/tenant-identity")
            ->assertOk();

        $manager = app(TenantConnectionManager::class);

        // Assert all context is wiped
        expect($manager->getActiveCompany())->toBeNull()
            ->and($manager->getActiveTenantIdentity())->toBeNull()
            ->and($manager->isConnectionOpen())->toBeFalse();

        // Assert sensitive in-memory configuration was blanked
        expect(Config::get('database.connections.tenant.password'))->toBe('')
            ->and(Config::get('database.connections.tenant.username'))->toBe('')
            ->and(Config::get('database.connections.tenant.database'))->toBe('');
    });
});
