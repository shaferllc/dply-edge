<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Auth\ConfirmPassword;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\VerifyEmail;
use Illuminate\Support\Facades\Route;

Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

// Passkey routes are auto-registered by the laravel/passkeys package — see config/passkeys.php
// for guard / middleware / throttle settings.

Route::middleware('guest')->group(function () {
    Route::livewire('register', Register::class)->name('register');
    Route::livewire('login', Login::class)->name('login');
    Route::livewire('two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.login');

    Route::livewire('forgot-password', ForgotPassword::class)->name('password.request');
    Route::livewire('reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Route::livewire('verify-email', VerifyEmail::class)->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::livewire('confirm-password', ConfirmPassword::class)->name('password.confirm');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // Stop impersonating — available to the (currently impersonated) session, not
    // admin-gated, since the effective user is no longer a platform admin. It is
    // a no-op unless the session was started by an admin via impersonation.
    Route::post('impersonate/leave', [ImpersonationController::class, 'leave'])
        ->name('impersonate.leave');
});
