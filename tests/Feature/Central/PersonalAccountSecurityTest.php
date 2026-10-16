<?php

use App\Models\User;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

describe('Personal Account and Security Settings', function () {
    test('user can update password with valid current password', function () {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123#'),
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->put('/settings/password', [
            'current_password' => 'CurrentPassword123#',
            'password' => 'NewComplexPassword123#',
            'password_confirmation' => 'NewComplexPassword123#',
        ]);

        $response->assertSessionHasNoErrors();
        expect(Hash::check('NewComplexPassword123#', $user->fresh()->password))->toBeTrue();

        $log = CentralAuditLog::where('action', 'auth.password_changed')->where('user_id', $user->id)->first();
        expect($log)->not->toBeNull();
    });

    test('password update fails when current password is wrong', function () {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123#'),
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->from('/settings/security')->put('/settings/password', [
            'current_password' => 'WrongCurrentPassword',
            'password' => 'NewComplexPassword123#',
            'password_confirmation' => 'NewComplexPassword123#',
        ]);

        $response->assertSessionHasErrors('current_password');
        expect(Hash::check('CurrentPassword123#', $user->fresh()->password))->toBeTrue();
    });

    test('updating email address resets verification and dispatches notification', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'old_email@rec-technology.com',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->patch('/settings/profile', [
            'name' => 'Updated Name',
            'email' => 'new_email@rec-technology.com',
        ]);

        $response->assertRedirect('/settings/profile');

        $refreshed = $user->fresh();
        expect($refreshed->email)->toBe('new_email@rec-technology.com');
        expect($refreshed->email_verified_at)->toBeNull();

        Notification::assertSentTo($user, VerifyEmail::class);

        $log = CentralAuditLog::where('action', 'auth.email_updated')->where('user_id', $user->id)->first();
        expect($log)->not->toBeNull();
    });

    test('user can view active sessions and log out from other browser sessions', function () {
        $user = User::factory()->create([
            'password' => Hash::make('SecretPass123#'),
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        // Seed 2 active sessions in central sessions table
        $currentSessionId = session()->getId();
        $otherSessionId = 'other_session_hash_12345';

        DB::connection('central')->table('sessions')->insert([
            [
                'id' => $currentSessionId,
                'user_id' => $user->id,
                'ip_address' => '192.168.1.10',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'payload' => 'payload1',
                'last_activity' => time(),
            ],
            [
                'id' => $otherSessionId,
                'user_id' => $user->id,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0)',
                'payload' => 'payload2',
                'last_activity' => time() - 3600,
            ],
        ]);

        // Visiting security page without password confirmation redirects to password.confirm
        $unconfirmed = $this->get('/settings/security');
        $unconfirmed->assertRedirect(route('password.confirm'));

        // Visiting with confirmed password succeeds and lists sessions
        $response = $this->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/security')
            ->has('sessions', 2)
        );

        $unrelatedUser = User::factory()->create();
        $unrelatedSessionId = 'unrelated_session_999';
        DB::connection('central')->table('sessions')->insert([
            'id' => $unrelatedSessionId,
            'user_id' => $unrelatedUser->id,
            'ip_address' => '172.16.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'payload_unrelated',
            'last_activity' => time(),
        ]);

        // Destroy other sessions with wrong password fails
        $wrongResponse = $this->delete('/settings/sessions', [
            'password' => 'WrongPass',
        ]);
        $wrongResponse->assertSessionHasErrors('password');
        expect(DB::connection('central')->table('sessions')->where('id', $otherSessionId)->exists())->toBeTrue();

        // Destroy other sessions with valid password succeeds
        $destroyResponse = $this->delete('/settings/sessions', [
            'password' => 'SecretPass123#',
        ]);
        $destroyResponse->assertSessionHasNoErrors();

        // Other session for this user is purged, unrelated user session remains untouched
        expect(DB::connection('central')->table('sessions')->where('id', $otherSessionId)->exists())->toBeFalse();
        expect(DB::connection('central')->table('sessions')->where('id', $unrelatedSessionId)->exists())->toBeTrue();

        $log = CentralAuditLog::where('action', 'auth.other_sessions_terminated')->where('user_id', $user->id)->first();
        expect($log)->not->toBeNull();
    });

    test('email verification notice page renders and resends verification link', function () {
        Notification::fake();

        $unverifiedUser = User::factory()->create([
            'email_verified_at' => null,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($unverifiedUser);

        // Access notice view
        $noticeResponse = $this->get('/email/verify');
        $noticeResponse->assertOk();
        $noticeResponse->assertInertia(fn ($page) => $page->component('auth/verify-email'));

        // Resend verification notification
        $resendResponse = $this->post('/email/verification-notification');
        $resendResponse->assertRedirect();

        Notification::assertSentTo($unverifiedUser, VerifyEmail::class);
    });
});
