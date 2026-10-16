<?php

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\CompanyOwnershipTransfer;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Notifications\CompanyOwnershipTransferNotification;
use App\Modules\Identity\Services\CompanyOwnershipTransferService;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use Database\Seeders\CentralDatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(CentralDatabaseSeeder::class);

    $this->company = Company::create([
        'legal_name' => 'Comercial Andina S.A.C.',
        'trade_name' => 'Andina Store',
        'slug' => 'andina-store-test',
        'tax_id' => '20123456780',
        'tax_id_type' => 'RUC',
        'contact_email' => 'admin@andina-test.pe',
        'country_code' => 'PE',
        'administrative_status' => CompanyStatus::Active,
    ]);

    $this->license = License::create([
        'company_id' => $this->company->id,
        'license_key' => 'TEST-LIC-ANDINA-001',
        'plan_name' => 'Pro Test Plan',
        'administrative_status' => LicenseAdministrativeStatus::Active,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'grace_period_days' => 7,
        'max_users' => 10,
        'max_branches' => 2,
    ]);
    $this->license->modules()->sync(Module::pluck('id')->all());

    $allCompanyPerms = Permission::where('scope', 'company')->get();

    $this->ownerRole = CompanyRole::create([
        'company_id' => $this->company->id,
        'slug' => 'company_owner',
        'name' => 'Propietario de Empresa',
        'is_system' => true,
    ]);
    $this->ownerRole->permissions()->sync($allCompanyPerms->pluck('id')->all());

    $this->adminRole = CompanyRole::create([
        'company_id' => $this->company->id,
        'slug' => 'company_admin',
        'name' => 'Administrador Comercial',
        'is_system' => true,
    ]);
    $this->adminRole->permissions()->sync($allCompanyPerms->whereIn('slug', [
        'commercial.company.view', 'commercial.company.update',
        'commercial.members.view', 'commercial.members.invite', 'commercial.members.assign_roles',
        'commercial.members.suspend', 'commercial.members.reactivate', 'commercial.members.revoke',
        'commercial.roles.view', 'commercial.roles.create', 'commercial.roles.update',
        'commercial.roles.assign_permissions', 'commercial.roles.delete',
    ])->pluck('id')->all());

    $this->limitedAdminRole = CompanyRole::create([
        'company_id' => $this->company->id,
        'slug' => 'limited_admin',
        'name' => 'Administrador Limitado',
        'is_system' => false,
    ]);
    $this->limitedAdminRole->permissions()->sync($allCompanyPerms->whereIn('slug', [
        'commercial.company.view', 'commercial.members.view', 'commercial.roles.view',
    ])->pluck('id')->all());

    // Create primary owner user
    $this->ownerUser = User::factory()->create([
        'name' => 'Titular Principal',
        'email' => 'titular@andina.test',
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->ownerMembership = Membership::create([
        'company_id' => $this->company->id,
        'user_id' => $this->ownerUser->id,
        'status' => MembershipStatus::Active,
        'is_owner' => true,
        'joined_at' => now(),
    ]);
    $this->ownerMembership->syncRoles([$this->ownerRole->id]);

    // Create target member for transfer (verified with 2FA)
    $this->targetMemberUser = User::factory()->create([
        'name' => 'Candidato Sucesor',
        'email' => 'sucesor@andina.test',
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->targetMembership = Membership::create([
        'company_id' => $this->company->id,
        'user_id' => $this->targetMemberUser->id,
        'status' => MembershipStatus::Active,
        'is_owner' => false,
        'joined_at' => now(),
    ]);
    $this->targetMembership->syncRoles([$this->adminRole->id]);
});

describe('Company Ownership & Granular IAM Hardening', function () {
    test('postgresql partial unique index physically blocks having multiple owners in the same company', function () {
        expect(function () {
            DB::connection('central')->table('memberships')->insert([
                'company_id' => $this->company->id,
                'user_id' => $this->targetMemberUser->id,
                'status' => 'active',
                'is_owner' => true,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        })->toThrow(QueryException::class);
    });

    test('active company owner cannot be suspended or revoked', function () {
        $this->actingAs($this->ownerUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        // Attempting to suspend owner
        $suspendResponse = $this->postJson("/app/{$this->company->slug}/members/{$this->ownerMembership->id}/toggle-suspension");
        $suspendResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['membership']);

        expect($this->ownerMembership->fresh()->status)->toBe(MembershipStatus::Active);

        // Attempting to revoke owner
        $revokeResponse = $this->deleteJson("/app/{$this->company->slug}/members/{$this->ownerMembership->id}");
        $revokeResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['membership']);

        expect(Membership::where('id', $this->ownerMembership->id)->exists())->toBeTrue();
    });

    test('non-owners cannot assign the company_owner role or initiate transfer', function () {
        $regularAdmin = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $regAdminMem = Membership::create([
            'company_id' => $this->company->id,
            'user_id' => $regularAdmin->id,
            'status' => MembershipStatus::Active,
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        $regAdminMem->syncRoles([$this->adminRole->id]);

        $this->actingAs($regularAdmin)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        // Attempt to assign owner role to target user
        $assignResponse = $this->putJson("/app/{$this->company->slug}/members/{$this->targetMembership->id}/roles", [
            'role_ids' => [$this->ownerRole->id],
        ]);
        $assignResponse->assertUnprocessable();

        // Attempt to initiate ownership transfer
        $transferResponse = $this->postJson("/app/{$this->company->slug}/ownership-transfers", [
            'target_user_id' => $this->targetMemberUser->id,
        ]);
        $transferResponse->assertForbidden();
    });

    test('limited admin without invite or suspend permissions is rejected with 403 on those routes', function () {
        $limitedUser = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $limitedMem = Membership::create([
            'company_id' => $this->company->id,
            'user_id' => $limitedUser->id,
            'status' => MembershipStatus::Active,
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        $limitedMem->syncRoles([$this->limitedAdminRole->id]);

        $this->actingAs($limitedUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        // Allowed to view members (commercial.members.view)
        $this->getJson("/app/{$this->company->slug}/members")->assertOk();

        // Forbidden to invite (commercial.members.invite)
        $this->postJson("/app/{$this->company->slug}/members/invite", [
            'email' => 'new.employee@test.com',
            'role_ids' => [$this->adminRole->id],
        ])->assertForbidden();

        // Forbidden to suspend (commercial.members.suspend)
        $this->postJson("/app/{$this->company->slug}/members/{$this->targetMembership->id}/toggle-suspension")
            ->assertForbidden();
    });

    test('ownership transfer requires recipient to be verified and 2FA enabled, and notifies recipient', function () {
        Notification::fake();

        $unverifiedCandidate = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => null,
            'two_factor_secret' => null,
        ]);
        Membership::create([
            'company_id' => $this->company->id,
            'user_id' => $unverifiedCandidate->id,
            'status' => MembershipStatus::Active,
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($this->ownerUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        // Attempt transfer to unverified user fails
        $failResponse = $this->postJson("/app/{$this->company->slug}/ownership-transfers", [
            'target_user_id' => $unverifiedCandidate->id,
        ]);
        $failResponse->assertUnprocessable();

        // Transfer to verified member with 2FA succeeds
        $successResponse = $this->postJson("/app/{$this->company->slug}/ownership-transfers", [
            'target_user_id' => $this->targetMemberUser->id,
        ]);
        $successResponse->assertRedirect();

        Notification::assertSentTo(
            $this->targetMemberUser,
            CompanyOwnershipTransferNotification::class,
            function (CompanyOwnershipTransferNotification $notification) {
                expect($notification->plainToken)->toHaveLength(40);

                return $notification->company->id === $this->company->id;
            }
        );

        $transfer = CompanyOwnershipTransfer::where('company_id', $this->company->id)->first();
        expect($transfer)->not->toBeNull()
            ->and($transfer->status)->toBe('pending')
            ->and($transfer->current_owner_id)->toBe($this->ownerUser->id)
            ->and($transfer->target_user_id)->toBe($this->targetMemberUser->id);
    });

    test('transfer acceptance atomically executes atomic ownership swap and records audit log', function () {
        Notification::fake();

        $transferService = app(CompanyOwnershipTransferService::class);

        // Owner initiates
        $this->actingAs($this->ownerUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);
        $transfer = $transferService->initiateTransfer($this->company, $this->ownerUser, $this->targetMemberUser);

        // Extract the plain token from the notification
        $plainToken = null;
        Notification::assertSentTo(
            $this->targetMemberUser,
            CompanyOwnershipTransferNotification::class,
            function (CompanyOwnershipTransferNotification $n) use (&$plainToken) {
                $plainToken = $n->plainToken;

                return true;
            }
        );
        expect($plainToken)->not->toBeNull();

        // Target member accepts
        $this->actingAs($this->targetMemberUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        $acceptResponse = $this->postJson("/app/{$this->company->slug}/ownership-transfers/{$plainToken}/accept");
        $acceptResponse->assertRedirect(route('company.workspace', ['company' => $this->company->slug]));

        // Verify atomic swap:
        // 1. Old owner is no longer owner, now has admin role
        $oldOwnerMem = $this->ownerMembership->fresh();
        expect($oldOwnerMem->is_owner)->toBeFalse()
            ->and($oldOwnerMem->roles->pluck('slug')->all())->toContain('company_admin');

        // 2. Target member is now the sole active owner
        $newOwnerMem = $this->targetMembership->fresh();
        expect($newOwnerMem->is_owner)->toBeTrue()
            ->and($newOwnerMem->roles->pluck('slug')->all())->toContain('company_owner');

        // 3. Exactly one owner remains in company
        expect(Membership::where('company_id', $this->company->id)->where('is_owner', true)->count())->toBe(1);

        // 4. Transfer status is accepted
        expect($transfer->fresh()->status)->toBe('accepted');

        // 5. Central audit log was recorded
        $audit = CentralAuditLog::where('action', 'company.ownership_transferred')
            ->where('auditable_id', (string) $this->company->id)
            ->first();
        expect($audit)->not->toBeNull()
            ->and($audit->user_id)->toBe($this->targetMemberUser->id);
    });

    test('privileged user holding company ownership cannot disable two factor authentication', function () {
        $this->actingAs($this->ownerUser)->withSession(['auth.2fa_passed' => true, 'auth.password_confirmed_at' => time()]);

        $response = $this->deleteJson('/user/two-factor-authentication');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['two_factor']);

        expect($this->ownerUser->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    });
});
