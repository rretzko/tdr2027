<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\SchoolEmailVerificationController;
use App\Livewire\Auth\SocialPhoneCheck;
use App\Livewire\Auth\SocialProfileComplete;
use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Livewire\Onboarding\TeacherOnboardingWizard;
use App\Livewire\Schools\Index as SchoolsIndex;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Students\Index as StudentsIndex;
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

// Signed, unauthenticated: clicked from an email inbox that may not have an app session.
Route::get('/school-email/verify/{schoolTeacher}', [SchoolEmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('school-email.verify');

// Profile completion: auth only — user may not yet have a verified email.
Route::middleware('auth')->group(function () {
    Route::get('/tdr/profile/complete', SocialProfileComplete::class)
        ->name('social.profile.complete');
});

// Onboarding wizard: outside the onboarding.complete-gated group below, else it would redirect to itself.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tdr/onboarding', TeacherOnboardingWizard::class)->name('teacher.onboarding');
});

Route::middleware(['auth', 'verified', 'onboarding.complete'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/schools', SchoolsIndex::class)->name('schools.index');
    Route::get('/students', StudentsIndex::class)->name('students.index');
    Route::view('/organizations', 'organizations')->name('organizations.index');
    Route::view('/events', 'events')->name('events.index');

    Route::get('/settings/profile', Profile::class)->name('settings.profile');
    Route::get('/settings/password', Password::class)->name('settings.password');
});
