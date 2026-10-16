<?php

use App\Models\User;
use App\Modules\Access\Enums\PermissionScope;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

describe('Platform Authorization (Central SaaS Administration)', function () {
    beforeEach(function () {
        // Ensure standard platform permissions exist
        $this->viewPerm = Permission::firstOrCreate(
            ['slug' => 'platform.companies.view'],
            [
                'scope' => PermissionScope::Platform,
                'name' => 'Ver Empresas',
            ]
        );

        $this->managePerm = Permission::firstOrCreate(
            ['slug' => 'platform.companies.manage'],
            [
                'scope' => PermissionScope::Platform,
                'name' => 'Administrar Empresas',
            ]
        );

        $this->operatorRole = PlatformRole::firstOrCreate(
            ['slug' => 'support_agent_test'],
            [
                'name' => 'Agente de Soporte',
            ]
        );
        $this->operatorRole->permissions()->sync([$this->viewPerm->id]);

        $this->superRole = PlatformRole::firstOrCreate(
            ['slug' => 'super_admin_test'],
            [
                'name' => 'Superadministrador',
            ]
        );
        $this->superRole->permissions()->sync([$this->viewPerm->id, $this->managePerm->id]);
    });

    test('guest is redirected to login when attempting to access platform routes', function () {
        $response = $this->get('/platform/companies');

        $response->assertRedirect('/login');
    });

    test('authenticated user without platform role is rejected with 403 on platform routes', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);

        $this->actingAs($user);

        $response = $this->get('/platform/companies');
        $response->assertForbidden();
    });

    test('platform operator with view permission can access platform companies index', function () {
        $operator = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $operator->platformRoles()->attach($this->operatorRole->id);

        $this->actingAs($operator)->withSession(['auth.2fa_passed' => true]);

        $response = $this->get('/platform/companies');
        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'message' => 'Catálogo central de empresas.',
        ]);
    });

    test('platform operator without manage permission is rejected with 403 on mutations', function () {
        $operator = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $operator->platformRoles()->attach($this->operatorRole->id);

        $this->actingAs($operator)->withSession(['auth.2fa_passed' => true]);

        $response = $this->post('/platform/companies', [
            'legal_name' => 'Nueva Empresa SAC',
        ]);
        $response->assertForbidden();
    });

    test('platform operator with manage permission can execute platform mutations', function () {
        $superAdmin = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $superAdmin->platformRoles()->attach($this->superRole->id);

        $this->actingAs($superAdmin)->withSession(['auth.2fa_passed' => true]);

        $response = $this->post('/platform/companies', [
            'legal_name' => 'Nueva Empresa SAC',
        ]);
        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'message' => 'Empresa creada centralmente.',
        ]);
    });

    test('platform operator does not have commercial access without explicit company membership', function () {
        $operator = User::factory()->create(['status' => UserStatus::Active]);
        $operator->platformRoles()->attach($this->superRole->id);

        $company = Company::create([
            'legal_name' => 'Empresa Privada SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20111111111',
            'slug' => 'empresa-privada',
            'contact_email' => 'privada@example.com',
        ]);

        $this->actingAs($operator);

        // Platform operator cannot enter private company without membership
        $response = $this->get('/app/empresa-privada');
        $response->assertNotFound();
    });

    test('suspended platform operator is denied access to platform routes', function () {
        $operator = User::factory()->create(['status' => UserStatus::Suspended]);
        $operator->platformRoles()->attach($this->superRole->id);

        $this->actingAs($operator);

        $response = $this->get('/platform/companies');
        // EnsureUserIsActive redirects suspended users to login
        $response->assertRedirect('/login');
    });

    test('platform operations do not open tenant database connection', function () {
        $superAdmin = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $superAdmin->platformRoles()->attach($this->superRole->id);

        $this->actingAs($superAdmin)->withSession(['auth.2fa_passed' => true]);

        $this->get('/platform/companies')->assertOk();

        // Confirm tenant connection was never initialized
        expect(array_key_exists('tenant', DB::getConnections()))->toBeFalse();
    });
});
