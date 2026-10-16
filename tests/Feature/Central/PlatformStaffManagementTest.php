<?php

use App\Models\User;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Access\Services\PlatformAuthorizationService;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use Database\Seeders\CentralDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(CentralDatabaseSeeder::class);
});

function createPlatformAdminUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ], $attributes));

    $superAdminRole = PlatformRole::where('slug', 'super_admin')->firstOrFail();
    $user->platformRoles()->attach($superAdminRole->id, ['assigned_at' => now()]);

    return $user;
}

describe('Parte B: Plataforma SaaS - Gestión de Personal y Accesos', function () {
    test('unauthorized users and operators without permission cannot view platform staff', function () {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        // Non-operator is denied
        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->get('/platform/staff')
            ->assertForbidden();

        // Operator with different role (e.g. support_agent without platform.staff.view) is denied
        $supportRole = PlatformRole::where('slug', 'support_agent')->firstOrFail();
        $user->platformRoles()->attach($supportRole->id, ['assigned_at' => now()]);

        $this->actingAs($user)
            ->withSession(['auth.2fa_passed' => true])
            ->get('/platform/staff')
            ->assertForbidden();
    });

    test('operator with platform.staff.view can list operators and available roles', function () {
        $admin = createPlatformAdminUser();

        $response = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson('/platform/staff');

        $response->assertOk()
            ->assertJsonStructure([
                'operators',
                'platformRoles',
                'canManage',
            ]);

        expect($response->json('canManage'))->toBeTrue();
        expect(collect($response->json('operators'))->pluck('email'))->toContain($admin->email);
    });

    test('operator with platform.staff.manage can onboard new operator and assign roles', function () {
        $admin = createPlatformAdminUser();
        $salesRole = PlatformRole::where('slug', 'sales_operator')->firstOrFail();

        $response = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson('/platform/staff', [
                'email' => 'nuevo.operador@platform.test',
                'name' => 'Carlos Operador',
                'role_ids' => [$salesRole->id],
            ]);

        $response->assertCreated();

        $newOperator = User::where('email', 'nuevo.operador@platform.test')->first();
        expect($newOperator)->not->toBeNull()
            ->and($newOperator->name)->toBe('Carlos Operador')
            ->and($newOperator->status)->toBe(UserStatus::Active)
            ->and($newOperator->platformRoles)->toHaveCount(1)
            ->and($newOperator->platformRoles->first()->slug)->toBe('sales_operator');

        $auditLog = CentralAuditLog::where('action', 'platform.staff.roles_assigned')
            ->where('auditable_id', (string) $newOperator->id)
            ->first();
        expect($auditLog)->not->toBeNull();
    });

    test('suspending platform access disables SaaS operations without deleting user or company memberships', function () {
        $admin = createPlatformAdminUser();

        // Operator has both platform role and an active membership in Company A
        $operator = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $supportRole = PlatformRole::where('slug', 'support_agent')->firstOrFail();
        $operator->platformRoles()->attach($supportRole->id, ['assigned_at' => now()]);

        $company = Company::create([
            'legal_name' => 'Comercial Andina S.A.C.',
            'trade_name' => 'Andina Store',
            'slug' => 'andina-store',
            'tax_id' => '20123456789',
            'tax_id_type' => 'RUC',
            'contact_email' => 'admin@andina.test',
            'country_code' => 'PE',
            'administrative_status' => CompanyStatus::Active,
        ]);

        $membership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $operator->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $platformAuth = app(PlatformAuthorizationService::class);
        expect($platformAuth->isOperator($operator))->toBeTrue();

        // Suspend operator's platform access
        $suspendResponse = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/platform/staff/{$operator->id}/toggle-suspension");
        $suspendResponse->assertOk();

        // Platform access is now denied
        expect($platformAuth->isOperator($operator->fresh()))->toBeFalse();
        $this->actingAs($operator)
            ->withSession(['auth.2fa_passed' => true])
            ->get('/platform/companies')
            ->assertForbidden();

        // Global user status remains Active
        expect($operator->fresh()->status)->toBe(UserStatus::Active);

        // Membership in Company A remains Active
        expect($membership->fresh()->status)->toBe(MembershipStatus::Active);

        // Reactivate platform access
        $reactivateResponse = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/platform/staff/{$operator->id}/toggle-suspension");
        $reactivateResponse->assertOk();
        expect($platformAuth->isOperator($operator->fresh()))->toBeTrue();
    });

    test('revoking platform access detaches platform roles while preserving user and company memberships', function () {
        $admin = createPlatformAdminUser();

        $operator = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $supportRole = PlatformRole::where('slug', 'support_agent')->firstOrFail();
        $operator->platformRoles()->attach($supportRole->id, ['assigned_at' => now()]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->deleteJson("/platform/staff/{$operator->id}");
        $response->assertOk();

        expect($operator->fresh()->platformRoles)->toHaveCount(0);
        expect(User::where('id', $operator->id)->exists())->toBeTrue();

        $auditLog = CentralAuditLog::where('action', 'platform.staff.access_revoked')
            ->where('auditable_id', (string) $operator->id)
            ->first();
        expect($auditLog)->not->toBeNull();
    });

    test('platform strictly prevents revoking or suspending the last active administrator', function () {
        $soleAdmin = createPlatformAdminUser();

        // Attempting to revoke access from the sole admin fails
        $revokeResponse = $this->actingAs($soleAdmin)
            ->withSession(['auth.2fa_passed' => true])
            ->deleteJson("/platform/staff/{$soleAdmin->id}");
        $revokeResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        // Attempting to suspend the sole admin fails
        $suspendResponse = $this->actingAs($soleAdmin)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/platform/staff/{$soleAdmin->id}/toggle-suspension");
        $suspendResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        // Attempting to strip the admin role down to support_agent fails
        $supportRole = PlatformRole::where('slug', 'support_agent')->firstOrFail();
        $updateRolesResponse = $this->actingAs($soleAdmin)
            ->withSession(['auth.2fa_passed' => true])
            ->putJson("/platform/staff/{$soleAdmin->id}/roles", [
                'role_ids' => [$supportRole->id],
            ]);
        $updateRolesResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        // Adding a second admin enables managing the first admin safely
        $secondAdmin = createPlatformAdminUser();

        $allowedSuspend = $this->actingAs($secondAdmin)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/platform/staff/{$soleAdmin->id}/toggle-suspension");
        $allowedSuspend->assertOk();
    });

    test('artisan platform:create-admin command provisions superadmin without hardcoded credentials', function () {
        $exitCode = Artisan::call('platform:create-admin', [
            'email' => 'primer.superadmin@rec-technology.test',
            '--name' => 'Admin Fundador',
            '--password' => 'PassSegura123#',
        ]);

        expect($exitCode)->toBe(0);

        $createdUser = User::where('email', 'primer.superadmin@rec-technology.test')->first();
        expect($createdUser)->not->toBeNull()
            ->and($createdUser->status)->toBe(UserStatus::Active)
            ->and($createdUser->email_verified_at)->not->toBeNull()
            ->and($createdUser->platformRoles->first()->slug)->toBe('super_admin');

        $log = CentralAuditLog::where('action', 'platform.admin_created')
            ->where('auditable_id', (string) $createdUser->id)
            ->first();
        expect($log)->not->toBeNull();
    });

    test('audit endpoint lists platform administrative actions', function () {
        $admin = createPlatformAdminUser();

        CentralAuditLog::create([
            'user_id' => $admin->id,
            'action' => 'platform.staff.test_event',
            'auditable_type' => 'App\Models\User',
            'auditable_id' => (string) $admin->id,
            'ip_address' => '127.0.0.1',
            'payload' => ['sample' => 'audit_data'],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.2fa_passed' => true])
            ->getJson('/platform/audit');
        $response->assertOk();
        expect(collect($response->json('data'))->pluck('action'))->toContain('platform.staff.test_event');
    });
});
