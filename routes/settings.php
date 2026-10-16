<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings', fn () => redirect()->to('/settings/profile'));

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::delete('user/two-factor-authentication', [SecurityController::class, 'disableTwoFactorAuthentication'])
        ->middleware('throttle:6,1')
        ->name('two-factor.disable');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::delete('settings/sessions', [SecurityController::class, 'destroyOtherSessions'])
        ->middleware('throttle:6,1')
        ->name('other-browser-sessions.destroy');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
