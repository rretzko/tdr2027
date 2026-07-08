<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\SchoolEmailVerificationController;
use App\Http\Controllers\StopImpersonatingController;
use App\Http\Controllers\StudentClaimController;
use App\Livewire\Auth\SocialPhoneCheck;
use App\Livewire\Auth\SocialProfileComplete;
use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Events\Show as EventsShow;
use App\Livewire\Events\VersionEdit;
use App\Livewire\Events\VersionInvitations;
use App\Livewire\Events\VersionPitchFiles;
use App\Livewire\Founder\Impersonate as FounderImpersonate;
use App\Livewire\Founder\MergeStudents as FounderMergeStudents;
use App\Livewire\Founder\TeacherVerification as FounderTeacherVerification;
use App\Livewire\Founder\TrackablePages as FounderTrackablePages;
use App\Livewire\Onboarding\TeacherOnboardingWizard;
use App\Livewire\Registrations\CandidateDetail;
use App\Livewire\Registrations\Index as RegistrationsIndex;
use App\Livewire\Registrations\VersionDashboard;
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

// Signed, unauthenticated: approve/deny links emailed to a student's existing
// teacher(s) when a different school/studio tries to claim that student.
Route::get('/student-claim/{student}/{teacher}/{school}/approve', [StudentClaimController::class, 'approve'])
    ->middleware('signed')
    ->name('student-claim.approve');
Route::get('/student-claim/{student}/{teacher}/{school}/deny', [StudentClaimController::class, 'deny'])
    ->middleware('signed')
    ->name('student-claim.deny');

// Profile completion: auth only — user may not yet have a verified email.
Route::middleware('auth')->group(function () {
    Route::get('/tdr/profile/complete', SocialProfileComplete::class)
        ->name('social.profile.complete');
});

// Onboarding wizard: outside the onboarding.complete-gated group below, else it would redirect to itself.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tdr/onboarding', TeacherOnboardingWizard::class)->name('teacher.onboarding');
});

// Founder routes: kept outside the onboarding.complete-gated group below — the
// Founder account has no Teacher profile, so that middleware would redirect it
// to the onboarding wizard.
Route::middleware(['auth', 'verified', 'founder'])->group(function () {
    Route::get('/founder/impersonate', FounderImpersonate::class)->name('founder.impersonate');
    Route::get('/founder/trackable-pages', FounderTrackablePages::class)->name('founder.trackable-pages');
    Route::get('/founder/merge-students', FounderMergeStudents::class)->name('founder.merge-students');
    Route::get('/founder/teacher-verification', FounderTeacherVerification::class)->name('founder.teacher-verification');
});

// Not behind the 'founder' middleware: once impersonating, the active user is
// the impersonated teacher, not the Founder — the controller itself checks
// session('impersonator_id') to confirm an impersonation is actually in progress.
Route::middleware(['auth'])->post('/founder/stop-impersonating', StopImpersonatingController::class)
    ->name('founder.stop-impersonating');

Route::middleware(['auth', 'verified', 'onboarding.complete'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/schools', SchoolsIndex::class)->name('schools.index');
    Route::view('/organizations', 'organizations')->name('organizations.index');

    // Students and Events both depend on having an active school to attach
    // records to, so both are gated behind the teacher having at least one.
    Route::middleware('has.active.school')->group(function () {
        Route::get('/students', StudentsIndex::class)->name('students.index');
        Route::get('/events', EventsIndex::class)->name('events.index');
        Route::get('/events/{event}', EventsShow::class)->name('events.show');
        Route::get('/events/versions/{version}/edit', VersionEdit::class)->name('events.versions.edit');
        Route::get('/events/versions/{version}/invitations', VersionInvitations::class)->name('events.versions.invitations');
        Route::get('/events/versions/{version}/pitch-files', VersionPitchFiles::class)->name('events.versions.pitch-files');
        Route::get('/registrations', RegistrationsIndex::class)->name('registrations.index');
        Route::get('/registrations/{version}', VersionDashboard::class)->name('registrations.version');
        Route::get('/registrations/{version}/{candidate}', CandidateDetail::class)->name('registrations.candidate');
    });

    Route::get('/settings/profile', Profile::class)->name('settings.profile');
    Route::get('/settings/password', Password::class)->name('settings.password');
});
