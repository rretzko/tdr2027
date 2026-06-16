<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Livewire\Auth\SocialPhoneCheck;
use App\Livewire\Auth\SocialProfileComplete;
use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome-tdr');
});

Route::middleware('guest')->group(function () {
    Route::get('/tdr/register', TeacherRegister::class)->name('tdr.register');
    Route::get('/sfdi/register', StudentRegister::class)->name('sfdi.register');

    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->name('social.redirect');

    Route::get('/tdr/social/phone', SocialPhoneCheck::class)
        ->name('social.phone.check');
});

// Callback is outside the guest group: also handles email-match for existing authenticated users.
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:social-callback')
    ->name('social.callback');

// Profile completion: auth only — user may not yet have a verified email.
Route::middleware('auth')->group(function () {
    Route::get('/tdr/profile/complete', SocialProfileComplete::class)
        ->name('social.profile.complete');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/settings/profile', Profile::class)->name('settings.profile');
    Route::get('/settings/password', Password::class)->name('settings.password');
});
