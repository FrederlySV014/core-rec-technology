<?php

use App\Models\User;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

describe('Central Authentication (Phase 3)', function () {
    test('successful login with active user creates session and records audit event in central connection', function () {
        $user = User::factory()->create([
            'email' => 'operador@rec-technology.com',
            'password' => Hash::make('CorrectPassword123#'),
            'status' => UserStatus::Active,
        ]);

        $response = $this->post('/login', [
            'email' => 'operador@rec-technology.com',
            'password' => 'CorrectPassword123#',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // Verify audit log in central database
        $log = CentralAuditLog::where('action', 'auth.login')->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->user_id)->toBe($user->id);
        expect($log->ip_address)->not->toBeEmpty();
        expect($log->new_values)->not->toHaveKey('password');
        expect($log->new_values)->not->toHaveKey('remember_token');
        expect($log->getConnectionName())->toBe('central');
    });

    test('case-insensitive email matching works on login', function () {
        $user = User::factory()->create([
            'email' => 'lowercase@rec-technology.com',
            'password' => Hash::make('SecretPass123#'),
            'status' => UserStatus::Active,
        ]);

        $response = $this->post('/login', [
            'email' => 'LOWERCASE@REC-TECHNOLOGY.COM',
            'password' => 'SecretPass123#',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    });

    test('login with incorrect credentials fails and logs failed attempt without password', function () {
        $user = User::factory()->create([
            'email' => 'user@rec-technology.com',
            'password' => Hash::make('CorrectPassword123#'),
            'status' => UserStatus::Active,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'user@rec-technology.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $log = CentralAuditLog::where('action', 'auth.failed_login')->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->user_id)->toBe($user->id);
        expect($log->old_values['attempted_email'])->toBe('user@rec-technology.com');
        expect($log->old_values)->not->toHaveKey('password');
    });

    test('login with suspended user is rejected even with correct password', function () {
        User::factory()->create([
            'email' => 'suspendido@rec-technology.com',
            'password' => Hash::make('ValidPassword123#'),
            'status' => UserStatus::Suspended,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'suspendido@rec-technology.com',
            'password' => 'ValidPassword123#',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    test('login with inactive user is rejected even with correct password', function () {
        User::factory()->create([
            'email' => 'inactivo@rec-technology.com',
            'password' => Hash::make('ValidPassword123#'),
            'status' => UserStatus::Inactive,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'inactivo@rec-technology.com',
            'password' => 'ValidPassword123#',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    test('active session is terminated on next request if user status becomes suspended or inactive', function () {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        // Active request works
        $response = $this->get('/dashboard');
        $response->assertOk();

        // Change status to suspended
        $user->status = UserStatus::Suspended;
        $user->save();

        // Next request must detect suspension, invalidate session and redirect to login
        $responseNext = $this->get('/dashboard');
        $responseNext->assertRedirect('/login');
        $this->assertGuest();
    });

    test('logout terminates session and logs auth.logout event', function () {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->post('/logout');
        $response->assertRedirect('/');
        $this->assertGuest();

        $log = CentralAuditLog::where('action', 'auth.logout')->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->user_id)->toBe($user->id);
    });

    test('unauthenticated users cannot access dashboard and are redirected to login', function () {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    });

    test('dashboard presents user authorized memberships without tenant database connection', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);

        $company = Company::create([
            'legal_name' => 'Empresa Dashboard SAC',
            'country_code' => 'PE',
            'tax_id_type' => 'RUC',
            'tax_id' => '20123987456',
            'slug' => 'empresa-dashboard',
            'contact_email' => 'dash@example.com',
            'administrative_status' => CompanyStatus::Active,
        ]);

        Membership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'is_owner' => true,
        ]);

        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('memberships', 1)
            ->where('memberships.0.company.slug', 'empresa-dashboard')
        );

        // Confirm tenant connection was never opened
        expect(DB::getDefaultConnection())->toBe('central');
    });

    test('rate limiting applies after consecutive failed attempts', function () {
        $email = 'bruteforce@rec-technology.com';

        User::factory()->create([
            'email' => $email,
            'password' => Hash::make('ActualPass123#'),
            'status' => UserStatus::Active,
        ]);

        RateLimiter::increment(md5('login'.implode('|', [$email, '127.0.0.1'])), amount: 5);

        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'WrongPass',
        ]);

        $response->assertTooManyRequests();
    });

    test('password reset triggers audit log in central database', function () {
        $user = User::factory()->create([
            'email' => 'reset@rec-technology.com',
            'password' => Hash::make('OldPassword123#'),
            'status' => UserStatus::Active,
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset@rec-technology.com',
            'password' => 'NewSecurePassword123#',
            'password_confirmation' => 'NewSecurePassword123#',
        ]);

        $response->assertRedirect('/login');
        expect(Hash::check('NewSecurePassword123#', $user->fresh()->password))->toBeTrue();

        $log = CentralAuditLog::where('action', 'auth.password_reset')->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->user_id)->toBe($user->id);
    });

    test('suspending a user between password verification and 2fa challenge prevents completing login', function () {
        $user = User::factory()->create([
            'email' => 'twofactor@rec-technology.com',
            'password' => Hash::make('Secret123#'),
            'status' => UserStatus::Active,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        // Step 1: Submit email and password
        $response = $this->post('/login', [
            'email' => 'twofactor@rec-technology.com',
            'password' => 'Secret123#',
        ]);

        $response->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        expect(session('login.id'))->toBe($user->id);

        // Suspend user before submitting 2FA
        $user->status = UserStatus::Suspended;
        $user->save();

        // Step 2: Attempt to submit 2FA code while suspended
        $challengeResponse = $this->post('/two-factor-challenge', [
            'recovery_code' => 'code-1',
        ]);

        $challengeResponse->assertRedirect('/login');
        $this->assertGuest();
        expect(session('login.id'))->toBeNull();
    });
});
