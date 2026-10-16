<?php

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\CompanyInvitation;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Notifications\CompanyInvitationNotification;
use App\Modules\Licensing\Enums\LicenseAdministrativeStatus;
use App\Modules\Licensing\Models\License;
use App\Modules\Licensing\Models\Module;
use Database\Seeders\CentralDatabaseSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(CentralDatabaseSeeder::class);
});

function createCompanyAdminTestUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ], $attributes));
}

describe('Partes C & D: Miembros, Invitaciones, Roles y Límites Empresariales', function () {
    function setupCompanyWithLicense(string $slug, int $maxUsers = 5): array
    {
        $company = Company::create([
            'legal_name' => "Empresa {$slug} S.A.C.",
            'trade_name' => "Comercial {$slug}",
            'tax_id' => '20'.str_pad((string) rand(10000000, 99999999), 9, '0', STR_PAD_LEFT),
            'tax_id_type' => 'RUC',
            'contact_email' => "contacto@{$slug}.test",
            'slug' => $slug,
            'country_code' => 'PE',
            'administrative_status' => CompanyStatus::Active,
        ]);

        $license = License::create([
            'company_id' => $company->id,
            'license_key' => "REC-{$slug}-".rand(1000, 9999),
            'plan_name' => 'Plan Pro',
            'administrative_status' => LicenseAdministrativeStatus::Active,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(30),
            'grace_period_days' => 5,
            'max_users' => $maxUsers,
            'max_branches' => 2,
        ]);

        $modules = Module::all();
        $license->modules()->attach($modules->pluck('id')->all());

        // Create standard company roles from templates
        $adminRole = CompanyRole::create([
            'company_id' => $company->id,
            'name' => 'Administrador de Empresa',
            'slug' => 'company_admin',
            'description' => 'Administrador de la empresa',
            'is_system' => true,
        ]);

        $sellerRole = CompanyRole::create([
            'company_id' => $company->id,
            'name' => 'Vendedor',
            'slug' => 'sales_seller',
            'description' => 'Vendedor comercial',
            'is_system' => false,
        ]);

        // Assign permissions to admin role
        $managePerm = Permission::where('slug', 'commercial.members.manage')->firstOrFail();
        $viewPerm = Permission::where('slug', 'commercial.company.view')->firstOrFail();
        $adminRole->permissions()->sync([$managePerm->id, $viewPerm->id]);
        $sellerRole->permissions()->sync([$viewPerm->id]);

        return [$company, $license, $adminRole, $sellerRole];
    }

    test('company admin can list members and invitations, isolated strictly to their company', function () {
        [$companyA, $licenseA, $adminRoleA, $sellerRoleA] = setupCompanyWithLicense('empresa-a');
        [$companyB, $licenseB, $adminRoleB, $sellerRoleB] = setupCompanyWithLicense('empresa-b');

        $adminUserA = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $membershipA = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $adminUserA->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipA->syncRoles([$adminRoleA->id]);

        $userB = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $membershipB = Membership::create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipB->syncRoles([$adminRoleB->id]);

        // Admin of Company A queries members
        $response = $this->actingAs($adminUserA)->withSession(['auth.2fa_passed' => true])->getJson("/app/{$companyA->slug}/members");
        $response->assertOk();

        $memberEmails = collect($response->json('members'))->pluck('user.email');
        expect($memberEmails)->toContain($adminUserA->email)
            ->and($memberEmails)->not->toContain($userB->email);

        // Admin of Company A cannot access Company B members (returns 404 to avoid leaking tenant existence)
        $this->actingAs($adminUserA)->getJson("/app/{$companyB->slug}/members")
            ->assertNotFound();
    });

    test('complete invitation flow: existing user must authenticate with matching account to accept', function () {
        [$companyA, $licenseA, $adminRoleA, $sellerRoleA] = setupCompanyWithLicense('empresa-alpha');

        $adminA = createCompanyAdminTestUser();
        $adminMembership = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $adminA->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $adminMembership->syncRoles([$adminRoleA->id]);

        $existingUser = User::factory()->create([
            'email' => 'empleado.existente@rec.test',
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);

        // 1. Admin sends invitation
        Notification::fake();

        $inviteResponse = $this->actingAs($adminA)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$companyA->slug}/members/invite", [
            'email' => 'empleado.existente@rec.test',
            'role_ids' => [$sellerRoleA->id],
        ]);
        $inviteResponse->assertCreated();
        expect($inviteResponse->json('plain_token'))->toBeNull(); // Token not leaked to admin

        $invitation = CompanyInvitation::where('company_id', $companyA->id)
            ->where('email', 'empleado.existente@rec.test')
            ->first();
        expect($invitation)->not->toBeNull()
            ->and($invitation->status)->toBe('pending');

        $token = null;
        Notification::assertSentTo(
            new AnonymousNotifiable,
            CompanyInvitationNotification::class,
            function ($notification, $channels, $notifiable) use (&$token) {
                if ($notifiable->routes['mail'] === 'empleado.existente@rec.test') {
                    $token = $notification->plainToken;

                    return true;
                }

                return false;
            }
        );
        expect($token)->not->toBeNull();

        // 2. Guest inspects invitation -> redirected to login
        auth()->logout();
        $guestResponse = $this->get("/invitations/{$token}");
        $guestResponse->assertRedirect(route('login'));

        // 3. Authenticated as wrong user -> forbidden
        $wrongUser = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $this->actingAs($wrongUser)->postJson("/invitations/{$token}/accept")
            ->assertForbidden();

        // 4. Authenticated as target user -> acceptance succeeds
        $acceptResponse = $this->actingAs($existingUser)->postJson("/invitations/{$token}/accept");
        $acceptResponse->assertOk();

        // Membership is created in Company A with the assigned role
        $membership = Membership::where('company_id', $companyA->id)
            ->where('user_id', $existingUser->id)
            ->first();
        expect($membership)->not->toBeNull()
            ->and($membership->status)->toBe(MembershipStatus::Active)
            ->and($membership->roles->pluck('id'))->toContain($sellerRoleA->id);

        expect($invitation->fresh()->status)->toBe('accepted');

        // Cannot re-use the invitation (throws 422 validation exception)
        $this->actingAs($existingUser)->postJson("/invitations/{$token}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation']);
    });

    test('complete invitation flow: new user onboards via token without public registration', function () {
        [$companyA, $licenseA, $adminRoleA, $sellerRoleA] = setupCompanyWithLicense('empresa-beta');

        $adminA = createCompanyAdminTestUser();
        $adminMembership = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $adminA->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $adminMembership->syncRoles([$adminRoleA->id]);

        Notification::fake();

        $inviteResponse = $this->actingAs($adminA)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$companyA->slug}/members/invite", [
            'email' => 'nuevo.colaborador@rec.test',
            'role_ids' => [$sellerRoleA->id],
        ]);
        $inviteResponse->assertCreated();
        expect($inviteResponse->json('plain_token'))->toBeNull(); // Token not leaked to admin

        $token = null;
        Notification::assertSentTo(
            new AnonymousNotifiable,
            CompanyInvitationNotification::class,
            function ($notification, $channels, $notifiable) use (&$token) {
                if ($notifiable->routes['mail'] === 'nuevo.colaborador@rec.test') {
                    $token = $notification->plainToken;

                    return true;
                }

                return false;
            }
        );
        expect($token)->not->toBeNull();

        // Public registration endpoint is disabled
        $this->post('/register', [
            'name' => 'Hacker',
            'email' => 'hacker@test.com',
            'password' => 'Pass123456#',
        ])->assertNotFound();

        // Onboard through invitation token
        $registerResponse = $this->postJson("/invitations/{$token}/register", [
            'name' => 'Lucía Martínez',
            'password' => 'SecurePassword123#',
            'password_confirmation' => 'SecurePassword123#',
        ]);
        $registerResponse->assertCreated();

        $newUser = User::where('email', 'nuevo.colaborador@rec.test')->first();
        expect($newUser)->not->toBeNull()
            ->and($newUser->name)->toBe('Lucía Martínez')
            ->and($newUser->status)->toBe(UserStatus::Active)
            ->and($newUser->email_verified_at)->not->toBeNull();

        $membership = Membership::where('company_id', $companyA->id)
            ->where('user_id', $newUser->id)
            ->first();
        expect($membership)->not->toBeNull()
            ->and($membership->status)->toBe(MembershipStatus::Active)
            ->and($membership->roles->pluck('id'))->toContain($sellerRoleA->id);
    });

    test('license max_users limit counts active memberships plus pending invitations', function () {
        // Setup company with strict max_users = 2
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('empresa-limitada', maxUsers: 2);

        $admin = createCompanyAdminTestUser();
        $adminMembership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $adminMembership->syncRoles([$adminRole->id]);

        // 1 active member out of 2 seats. 1 seat remaining.
        // Send 1st invitation -> now 2 out of 2 seats occupied (1 active + 1 pending)
        $invite1 = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/invite", [
            'email' => 'persona1@test.com',
            'role_ids' => [$sellerRole->id],
        ]);
        $invite1->assertCreated();

        // Attempting to invite a 2nd person exceeds limit (3 > 2)
        $invite2 = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/invite", [
            'email' => 'persona2@test.com',
            'role_ids' => [$sellerRole->id],
        ]);
        $invite2->assertUnprocessable()
            ->assertJsonValidationErrors(['license']);

        // Cancel the 1st invitation -> frees the seat
        $invitationRecord = CompanyInvitation::where('company_id', $company->id)->where('email', 'persona1@test.com')->first();
        $cancelResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->deleteJson("/app/{$company->slug}/members/invitations/{$invitationRecord->id}");
        $cancelResponse->assertOk();

        // Now inviting persona2 succeeds!
        $retryInvite = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/invite", [
            'email' => 'persona2@test.com',
            'role_ids' => [$sellerRole->id],
        ]);
        $retryInvite->assertCreated();
    });

    test('suspending membership in company A does not suspend user global account nor access in company B', function () {
        [$companyA, $licenseA, $adminRoleA, $sellerRoleA] = setupCompanyWithLicense('co-a');
        [$companyB, $licenseB, $adminRoleB, $sellerRoleB] = setupCompanyWithLicense('co-b');

        $adminA = createCompanyAdminTestUser();
        $membershipAdminA = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $adminA->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipAdminA->syncRoles([$adminRoleA->id]);

        // User belongs to both Company A and Company B
        $user = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $memberA = Membership::create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $memberA->syncRoles([$sellerRoleA->id]);

        $memberB = Membership::create([
            'company_id' => $companyB->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $memberB->syncRoles([$sellerRoleB->id]);

        // Admin of Company A suspends memberA
        $suspendResponse = $this->actingAs($adminA)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$companyA->slug}/members/{$memberA->id}/toggle-suspension");
        $suspendResponse->assertOk();

        // Membership in Company A is suspended
        expect($memberA->fresh()->status)->toBe(MembershipStatus::Suspended);

        // Global User status is still Active
        expect($user->fresh()->status)->toBe(UserStatus::Active);

        // Membership in Company B is still Active
        expect($memberB->fresh()->status)->toBe(MembershipStatus::Active);

        // Reactivate membership in Company A
        $reactivateResponse = $this->actingAs($adminA)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$companyA->slug}/members/{$memberA->id}/toggle-suspension");
        $reactivateResponse->assertOk();
        expect($memberA->fresh()->status)->toBe(MembershipStatus::Active);
    });

    test('cannot revoke, suspend or demote the last active company administrator', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-sole-admin');

        $soleAdmin = createCompanyAdminTestUser();
        $membership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $soleAdmin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membership->syncRoles([$adminRole->id]);

        // Attempting to suspend the sole admin fails
        $this->actingAs($soleAdmin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/{$membership->id}/toggle-suspension")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['member']);

        // Attempting to revoke the sole admin fails
        $this->actingAs($soleAdmin)->withSession(['auth.2fa_passed' => true])->deleteJson("/app/{$company->slug}/members/{$membership->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['member']);

        // Attempting to strip the admin role to seller fails
        $this->actingAs($soleAdmin)->withSession(['auth.2fa_passed' => true])->putJson("/app/{$company->slug}/members/{$membership->id}/roles", [
            'role_ids' => [$sellerRole->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['member']);
    });

    test('company custom roles CRUD respects permission delegation boundary and protects system roles', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-custom-roles');

        $admin = createCompanyAdminTestUser();
        $membership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membership->syncRoles([$adminRole->id]);

        $companyPerm = Permission::where('slug', 'commercial.company.view')->firstOrFail();
        $platformPerm = Permission::where('slug', 'platform.companies.manage')->firstOrFail();

        // 1. Create custom role with company permission
        $createResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/roles", [
            'name' => 'Supervisor de Turno',
            'slug' => 'shift_supervisor',
            'description' => 'Supervisa operaciones de tienda',
            'permission_ids' => [$companyPerm->id, $platformPerm->id], // Attempting to sneak in a platform permission
        ]);
        $createResponse->assertCreated();

        $customRole = CompanyRole::where('company_id', $company->id)->where('slug', 'shift_supervisor')->first();
        expect($customRole)->not->toBeNull()
            ->and($customRole->is_system)->toBeFalse();

        // Platform permission is pruned/ignored by the delegation boundary!
        expect($customRole->permissions->pluck('id'))->toContain($companyPerm->id)
            ->and($customRole->permissions->pluck('id'))->not->toContain($platformPerm->id);

        // 2. System role cannot be deleted
        $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->deleteJson("/app/{$company->slug}/roles/{$adminRole->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        // 3. Custom role can be deleted when no members are assigned
        $deleteResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->deleteJson("/app/{$company->slug}/roles/{$customRole->id}");
        $deleteResponse->assertOk();
        expect(CompanyRole::where('id', $customRole->id)->exists())->toBeFalse();
    });

    test('during grace period and expired license, company security admin can perform necessary identity actions', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-expired-sec');

        $admin = createCompanyAdminTestUser();
        $membershipAdmin = Membership::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipAdmin->syncRoles([$adminRole->id]);

        $employee = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $membershipEmployee = Membership::create([
            'company_id' => $company->id,
            'user_id' => $employee->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipEmployee->syncRoles([$sellerRole->id]);

        // Simulate expired license
        $license->update([
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(20),
            'effective_ends_at' => now()->subDays(15),
        ]);

        // Commercial operation is strictly forbidden (403)
        $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/test/sales/create")
            ->assertForbidden();

        // BUT security admin operation (revoking a compromised member) is permitted!
        $revokeResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->deleteJson("/app/{$company->slug}/members/{$membershipEmployee->id}");
        $revokeResponse->assertOk();

        expect($membershipEmployee->fresh()->status)->toBe(MembershipStatus::Revoked);
    });

    test('limited admin cannot delegate permissions outside their own delegation capacity or modify self membership', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-delegation');

        // Create limited admin role with only members.manage and company.view
        $limitedAdminRole = CompanyRole::create([
            'company_id' => $company->id,
            'name' => 'Admin Limitado',
            'slug' => 'limited_admin',
            'description' => 'Solo miembros',
            'is_system' => false,
        ]);
        $membersPerm = Permission::where('slug', 'commercial.members.manage')->firstOrFail();
        $limitedAdminRole->permissions()->sync([$membersPerm->id]);

        $limitedAdminUser = createCompanyAdminTestUser();
        $membershipLimited = Membership::create([
            'company_id' => $company->id,
            'user_id' => $limitedAdminUser->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipLimited->syncRoles([$limitedAdminRole->id]);

        // Second admin to avoid last admin block
        $otherAdminUser = createCompanyAdminTestUser();
        $otherMembership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $otherAdminUser->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $otherMembership->syncRoles([$adminRole->id]);

        // 1. Limited admin tries to modify their own roles -> Forbidden / 422
        $selfModResponse = $this->actingAs($limitedAdminUser)
            ->withSession(['auth.2fa_passed' => true])
            ->putJson("/app/{$company->slug}/members/{$membershipLimited->id}/roles", [
                'role_ids' => [$adminRole->id],
            ]);
        $selfModResponse->assertStatus(422);

        // 2. Limited admin tries to assign a role containing permissions they do not possess to another member -> 422
        $targetUser = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $targetMembership = Membership::create([
            'company_id' => $company->id,
            'user_id' => $targetUser->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $targetMembership->syncRoles([$sellerRole->id]);

        $escalateAssignResponse = $this->actingAs($limitedAdminUser)
            ->withSession(['auth.2fa_passed' => true])
            ->putJson("/app/{$company->slug}/members/{$targetMembership->id}/roles", [
                'role_ids' => [$adminRole->id], // adminRole has commercial.company.view which limitedAdmin lacks
            ]);
        $escalateAssignResponse->assertStatus(422);

        // 3. Limited admin tries to invite a user with roles containing permissions they lack -> 422
        $escalateInviteResponse = $this->actingAs($limitedAdminUser)
            ->withSession(['auth.2fa_passed' => true])
            ->postJson("/app/{$company->slug}/members/invite", [
                'email' => 'externo@empresa.test',
                'role_ids' => [$adminRole->id],
            ]);
        $escalateInviteResponse->assertStatus(422);
    });

    test('expired license allows consultations and access reduction but forbids new invites, reactivations, and role expansions', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-expired-granular');

        $admin = createCompanyAdminTestUser();
        $membershipAdmin = Membership::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipAdmin->syncRoles([$adminRole->id]);

        $member = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $membershipMember = Membership::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipMember->syncRoles([$sellerRole->id]);

        // Expire license
        $license->update([
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(20),
            'effective_ends_at' => now()->subDays(15),
        ]);

        // 1. Reading members list is allowed
        $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->getJson("/app/{$company->slug}/members")->assertOk();

        // 2. Suspending active member is allowed (access reduction)
        $suspendResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/{$membershipMember->id}/toggle-suspension");
        $suspendResponse->assertOk();
        expect($membershipMember->fresh()->status)->toBe(MembershipStatus::Suspended);

        // 3. Reactivating suspended member with expired license is BLOCKED (422)
        $reactivateResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/{$membershipMember->id}/toggle-suspension");
        $reactivateResponse->assertStatus(422);

        // 4. Creating new invitation with expired license is BLOCKED (422)
        $inviteResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/members/invite", [
            'email' => 'new-expired@test.com',
            'role_ids' => [$sellerRole->id],
        ]);
        $inviteResponse->assertStatus(422);

        // 5. Creating new custom roles with expired license is BLOCKED (422)
        $roleCreateResponse = $this->actingAs($admin)->withSession(['auth.2fa_passed' => true])->postJson("/app/{$company->slug}/roles", [
            'name' => 'Rol Prohibido',
            'slug' => 'rol_prohibido',
            'description' => 'Test',
            'permission_ids' => [$sellerRole->permissions->first()->id],
        ]);
        $roleCreateResponse->assertStatus(422);
    });

    test('profile deletion performs soft identity closure and blocks if user is last admin of company or platform', function () {
        [$company, $license, $adminRole, $sellerRole] = setupCompanyWithLicense('co-profile-del');

        $soleAdmin = User::factory()->create([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => bcrypt('password123'),
        ]);
        $membershipSole = Membership::create([
            'company_id' => $company->id,
            'user_id' => $soleAdmin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipSole->syncRoles([$adminRole->id]);

        // 1. Trying to delete account while being sole company admin -> ValidationException (422)
        $deleteAttempt = $this->actingAs($soleAdmin)->delete('/settings/profile', [
            'password' => 'password123',
        ]);
        $deleteAttempt->assertSessionHasErrors('password');

        // 2. Add second admin so sole admin is no longer the last
        $secondAdmin = User::factory()->create(['status' => UserStatus::Active, 'email_verified_at' => now()]);
        $membershipSecond = Membership::create([
            'company_id' => $company->id,
            'user_id' => $secondAdmin->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membershipSecond->syncRoles([$adminRole->id]);

        // 3. Perform account deletion -> soft closure
        $successfulDelete = $this->actingAs($soleAdmin)->delete('/settings/profile', [
            'password' => 'password123',
        ]);
        $successfulDelete->assertRedirect('/');

        // User is soft deactivated and NOT hard-deleted from database
        $freshUser = User::find($soleAdmin->id);
        expect($freshUser)->not->toBeNull()
            ->and($freshUser->status)->toBe(UserStatus::Inactive);

        // Their company membership is revoked
        expect($membershipSole->fresh()->status)->toBe(MembershipStatus::Revoked);
    });
});
