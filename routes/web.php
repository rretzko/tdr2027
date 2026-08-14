<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\CandidateApplicationPdfController;
use App\Http\Controllers\CandidateScoreReportPdfController;
use App\Http\Controllers\PaymentRegisterCsvController;
use App\Http\Controllers\PaymentRegisterPdfController;
use App\Http\Controllers\Reports\AdjudicationBackupExportController;
use App\Http\Controllers\Reports\CandidateCountsExportController;
use App\Http\Controllers\Reports\ObligatedTeachersPdfController;
use App\Http\Controllers\Reports\ParticipatingCandidatesPdfController;
use App\Http\Controllers\Reports\ParticipatingSchoolsPdfController;
use App\Http\Controllers\Reports\ParticipatingTeachersPdfController;
use App\Http\Controllers\Reports\ParticipationByCountyExportController;
use App\Http\Controllers\Reports\PaymentReconciliationPdfController;
use App\Http\Controllers\Reports\RegistrationCardsPdfController;
use App\Http\Controllers\Reports\TabRoom\AuditionScoresPdfController;
use App\Http\Controllers\Reports\TabRoom\CombinedAuditionScoresExportController;
use App\Http\Controllers\Reports\TabRoom\EnsembleParticipationExportController;
use App\Http\Controllers\Reports\TabRoom\StudentSeniorityExportController;
use App\Http\Controllers\SchoolEmailVerificationController;
use App\Http\Controllers\SchoolScoreReportPdfController;
use App\Http\Controllers\SharedScoresPdfController;
use App\Http\Controllers\StopImpersonatingController;
use App\Http\Controllers\StudentClaimController;
use App\Http\Controllers\VersionInvitationRequestController;
use App\Http\Controllers\VersionRoomRosterPdfController;
use App\Http\Controllers\Webhooks\PaypalReturnController;
use App\Http\Controllers\Webhooks\PaypalWebhookController;
use App\Http\Controllers\Webhooks\SquareWebhookController;
use App\Livewire\Auth\SocialPhoneCheck;
use App\Livewire\Auth\SocialProfileComplete;
use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Livewire\Events\Adjudicate;
use App\Livewire\Events\CreateEvent;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Events\Reports\AdjudicationBackup;
use App\Livewire\Events\Reports\CandidateCounts;
use App\Livewire\Events\Reports\Index as ReportsIndex;
use App\Livewire\Events\Reports\ObligatedTeachers;
use App\Livewire\Events\Reports\ParticipatingCandidates;
use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Livewire\Events\Reports\ParticipatingTeachers;
use App\Livewire\Events\Reports\ParticipationByCounty;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Livewire\Events\Reports\RegistrationCards;
use App\Livewire\Events\Show as EventsShow;
use App\Livewire\Events\TabRoom\AddEditScores as TabRoomAddEditScores;
use App\Livewire\Events\TabRoom\AdjudicationTracking as TabRoomAdjudicationTracking;
use App\Livewire\Events\TabRoom\CloseAudition as TabRoomCloseAudition;
use App\Livewire\Events\TabRoom\EnsembleCutoffs as TabRoomEnsembleCutoffs;
use App\Livewire\Events\TabRoom\Index as TabRoomIndex;
use App\Livewire\Events\TabRoom\Reports\AuditionScores as TabRoomAuditionScores;
use App\Livewire\Events\TabRoom\Reports\CombinedAuditionScoresConfidential;
use App\Livewire\Events\TabRoom\Reports\CombinedAuditionScoresPublic;
use App\Livewire\Events\TabRoom\Reports\EnsembleParticipation as TabRoomEnsembleParticipation;
use App\Livewire\Events\TabRoom\Reports\Index as TabRoomReportsIndex;
use App\Livewire\Events\TabRoom\Reports\StudentSeniority as TabRoomStudentSeniority;
use App\Livewire\Events\VersionCoRegistrationManagers;
use App\Livewire\Events\VersionEdit;
use App\Livewire\Events\VersionInvitations;
use App\Livewire\Events\VersionPitchFiles;
use App\Livewire\Events\VersionRooms;
use App\Livewire\Events\VersionScoringRubric;
use App\Livewire\Events\WebRegistration;
use App\Livewire\Founder\Impersonate as FounderImpersonate;
use App\Livewire\Founder\MergeStudents as FounderMergeStudents;
use App\Livewire\Founder\TeacherVerification as FounderTeacherVerification;
use App\Livewire\Founder\TrackablePages as FounderTrackablePages;
use App\Livewire\Onboarding\TeacherOnboardingWizard;
use App\Livewire\Registrations\CandidateDetail;
use App\Livewire\Registrations\EstimateForm;
use App\Livewire\Registrations\Index as RegistrationsIndex;
use App\Livewire\Registrations\RequestInvitation;
use App\Livewire\Registrations\Results;
use App\Livewire\Registrations\ResultsIndex;
use App\Livewire\Registrations\VersionDashboard;
use App\Livewire\Registrations\VersionObligations;
use App\Livewire\Schools\Index as SchoolsIndex;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Students\Index as StudentsIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome-tdr');
});

// Vendor e-payment webhooks (epayment-integration.md §2.2/§2.4) — public,
// unauthenticated, CSRF-excluded (see bootstrap/app.php's validateCsrfTokens
// except list), rate-limited by IP. Each gateway verifies its own signature
// before doing anything with the payload. {event} is per-business, not a
// convenience — each Square/PayPal business (event_epayment_configs, §1.2)
// has its own webhook subscription and signing key (§5 item 8), so the URL
// itself must identify which key to verify against before the payload can
// be trusted at all.
Route::post('/webhooks/payments/square/{event}', SquareWebhookController::class)
    ->middleware('throttle:payment-webhooks')
    ->name('webhooks.payments.square');

Route::post('/webhooks/payments/paypal/{event}', PaypalWebhookController::class)
    ->middleware('throttle:payment-webhooks')
    ->name('webhooks.payments.paypal');

// Where PayPal sends the payer's browser back after approving an order —
// see PaypalReturnController's own docblock for why PayPal needs this and
// Square doesn't. GET, not a webhook: no signature to verify (the browser,
// not PayPal's servers, makes this request), so no CSRF exemption needed
// either — GET requests aren't covered by CSRF protection to begin with.
Route::get('/payments/paypal/return', PaypalReturnController::class)
    ->name('payments.paypal.return');

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

// Signed, unauthenticated: approve/deny links emailed to each Event Manager
// when a teacher requests a Version invitation (§5.8). {user} is the specific
// recipient the link was generated for, so decided_by can be attributed
// without an active session.
Route::get('/version-invitation-requests/{versionInvitationRequest}/{user}/approve', [VersionInvitationRequestController::class, 'approve'])
    ->middleware('signed')
    ->name('version-invitation-requests.approve');
Route::get('/version-invitation-requests/{versionInvitationRequest}/{user}/deny', [VersionInvitationRequestController::class, 'deny'])
    ->middleware('signed')
    ->name('version-invitation-requests.deny');

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

    // Students and Registrations depend on having an active school to attach
    // records to, so both are gated behind the teacher having at least one.
    Route::middleware('has.active.school')->group(function () {
        Route::get('/students', StudentsIndex::class)->name('students.index');
        Route::get('/registrations', RegistrationsIndex::class)->name('registrations.index');
        Route::get('/registrations/results', ResultsIndex::class)->name('registrations.results-index');
        Route::get('/registrations/{version}', VersionDashboard::class)->name('registrations.version');
        Route::get('/registrations/{version}/request-invitation', RequestInvitation::class)->name('registrations.request-invitation');
        Route::get('/registrations/{version}/obligations', VersionObligations::class)->name('registrations.obligations');
        Route::get('/registrations/{version}/results', Results::class)->name('registrations.results');
        Route::get('/registrations/{version}/results/schools/{school}/report.pdf', SchoolScoreReportPdfController::class)->name('registrations.results.school-report-pdf');
        Route::get('/registrations/{version}/results/shared-scores.pdf', SharedScoresPdfController::class)->name('registrations.results.shared-scores-pdf');
        Route::get('/registrations/{version}/estimate-form', EstimateForm::class)->name('registrations.estimate-form');
        Route::get('/registrations/{version}/payment-register.csv', PaymentRegisterCsvController::class)->name('registrations.payment-register-csv');
        Route::get('/registrations/{version}/payment-register.pdf', PaymentRegisterPdfController::class)->name('registrations.payment-register-pdf');
        Route::get('/registrations/{version}/{candidate}', CandidateDetail::class)->name('registrations.candidate');
        Route::get('/registrations/{version}/{candidate}/application.pdf', CandidateApplicationPdfController::class)->name('registrations.candidate.application-pdf');
        Route::get('/registrations/{version}/{candidate}/score-report.pdf', CandidateScoreReportPdfController::class)->name('registrations.results.candidate-report-pdf');
    });

    // Events is gated behind having an active school OR holding a
    // version-scoped role (Event Manager, Registration Manager, etc.) on at
    // least one Version — a teacher administering an Event doesn't need an
    // active school of their own to do so.
    Route::middleware('can.access.events')->group(function () {
        Route::get('/events', EventsIndex::class)->name('events.index');
        Route::get('/events/new', CreateEvent::class)->name('events.create');
        Route::get('/events/{event}', EventsShow::class)->name('events.show');
        Route::get('/events/versions/{version}/edit', VersionEdit::class)->name('events.versions.edit');
        Route::get('/events/versions/{version}/invitations', VersionInvitations::class)->name('events.versions.invitations');
        Route::get('/events/versions/{version}/co-registration-managers', VersionCoRegistrationManagers::class)->name('events.versions.co-registration-managers');
        Route::get('/events/versions/{version}/pitch-files', VersionPitchFiles::class)->name('events.versions.pitch-files');
        Route::get('/events/versions/{version}/rooms', VersionRooms::class)->name('events.versions.rooms');
        Route::get('/events/versions/{version}/rooms/roster.pdf', VersionRoomRosterPdfController::class)->name('events.versions.rooms.roster-pdf');
        Route::get('/events/versions/{version}/scoring-rubric', VersionScoringRubric::class)->name('events.versions.scoring-rubric');
        Route::get('/events/versions/{version}/adjudicate', Adjudicate::class)->name('events.versions.adjudicate');

        // Tab Room Module (Tab Room Module.docx). Phase 1: Add/Edit Scores +
        // Adjudication Tracking. Phase 2: Ensemble Cut-offs. Reports and
        // Close Audition land in a later phase.
        Route::get('/events/versions/{version}/tab-room', TabRoomIndex::class)->name('events.versions.tab-room.index');
        Route::get('/events/versions/{version}/tab-room/scores', TabRoomAddEditScores::class)->name('events.versions.tab-room.scores');
        Route::get('/events/versions/{version}/tab-room/tracking', TabRoomAdjudicationTracking::class)->name('events.versions.tab-room.tracking');
        Route::get('/events/versions/{version}/tab-room/cutoffs', TabRoomEnsembleCutoffs::class)->name('events.versions.tab-room.cutoffs');
        Route::get('/events/versions/{version}/tab-room/close', TabRoomCloseAudition::class)->name('events.versions.tab-room.close');
        Route::get('/events/versions/{version}/tab-room/reports', TabRoomReportsIndex::class)->name('events.versions.tab-room.reports.index');
        Route::get('/events/versions/{version}/tab-room/reports/audition-scores', TabRoomAuditionScores::class)->name('events.versions.tab-room.reports.audition-scores');
        Route::get('/events/versions/{version}/tab-room/reports/audition-scores/export.pdf', AuditionScoresPdfController::class)->name('events.versions.tab-room.reports.audition-scores.pdf');
        Route::get('/events/versions/{version}/tab-room/reports/combined-scores/confidential', CombinedAuditionScoresConfidential::class)->name('events.versions.tab-room.reports.combined-scores-confidential');
        Route::get('/events/versions/{version}/tab-room/reports/combined-scores/confidential/export.{format}', CombinedAuditionScoresExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.tab-room.reports.combined-scores-confidential.export');
        Route::get('/events/versions/{version}/tab-room/reports/combined-scores/public', CombinedAuditionScoresPublic::class)->name('events.versions.tab-room.reports.combined-scores-public');
        Route::get('/events/versions/{version}/tab-room/reports/combined-scores/public/export.{format}', CombinedAuditionScoresExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.tab-room.reports.combined-scores-public.export');
        Route::get('/events/versions/{version}/tab-room/reports/ensemble-participation', TabRoomEnsembleParticipation::class)->name('events.versions.tab-room.reports.ensemble-participation');
        Route::get('/events/versions/{version}/tab-room/reports/ensemble-participation/export.{format}', EnsembleParticipationExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.tab-room.reports.ensemble-participation.export');
        Route::get('/events/versions/{version}/tab-room/reports/student-seniority', TabRoomStudentSeniority::class)->name('events.versions.tab-room.reports.student-seniority');
        Route::get('/events/versions/{version}/tab-room/reports/student-seniority/export.{format}', StudentSeniorityExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.tab-room.reports.student-seniority.export');

        // Web Registration Manager Module (event-version-orientation.md §5.11).
        Route::get('/events/versions/{version}/web-registration', WebRegistration::class)->name('events.versions.web-registration');

        // Registration Manager Reporting Module (event-version-orientation.md §5.10).
        Route::get('/events/versions/{version}/reports', ReportsIndex::class)->name('events.versions.reports');
        Route::get('/events/versions/{version}/reports/obligated-teachers', ObligatedTeachers::class)->name('events.versions.reports.obligated-teachers');
        Route::get('/events/versions/{version}/reports/obligated-teachers/export.pdf', ObligatedTeachersPdfController::class)->name('events.versions.reports.obligated-teachers.pdf');
        Route::get('/events/versions/{version}/reports/participating-teachers', ParticipatingTeachers::class)->name('events.versions.reports.participating-teachers');
        Route::get('/events/versions/{version}/reports/participating-teachers/export.pdf', ParticipatingTeachersPdfController::class)->name('events.versions.reports.participating-teachers.pdf');
        Route::get('/events/versions/{version}/reports/participating-schools', ParticipatingSchools::class)->name('events.versions.reports.participating-schools');
        Route::get('/events/versions/{version}/reports/participating-schools/export.pdf', ParticipatingSchoolsPdfController::class)->name('events.versions.reports.participating-schools.pdf');
        Route::get('/events/versions/{version}/reports/payment-reconciliation', PaymentReconciliation::class)->name('events.versions.reports.payment-reconciliation');
        Route::get('/events/versions/{version}/reports/payment-reconciliation/export.pdf', PaymentReconciliationPdfController::class)->name('events.versions.reports.payment-reconciliation.pdf');
        Route::get('/events/versions/{version}/reports/participating-candidates', ParticipatingCandidates::class)->name('events.versions.reports.participating-candidates');
        Route::get('/events/versions/{version}/reports/participating-candidates/export.pdf', ParticipatingCandidatesPdfController::class)->name('events.versions.reports.participating-candidates.pdf');
        Route::get('/events/versions/{version}/reports/participation-by-county', ParticipationByCounty::class)->name('events.versions.reports.participation-by-county');
        Route::get('/events/versions/{version}/reports/participation-by-county/export.{format}', ParticipationByCountyExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.reports.participation-by-county.export');
        Route::get('/events/versions/{version}/reports/candidate-counts', CandidateCounts::class)->name('events.versions.reports.candidate-counts');
        Route::get('/events/versions/{version}/reports/candidate-counts/export.{format}', CandidateCountsExportController::class)->whereIn('format', ['pdf', 'csv'])->name('events.versions.reports.candidate-counts.export');
        Route::get('/events/versions/{version}/reports/adjudication-backup', AdjudicationBackup::class)->name('events.versions.reports.adjudication-backup');
        Route::get('/events/versions/{version}/reports/adjudication-backup/export.{type}', AdjudicationBackupExportController::class)->whereIn('type', ['paper', 'csv', 'checklist'])->name('events.versions.reports.adjudication-backup.export');
        Route::get('/events/versions/{version}/reports/registration-cards', RegistrationCards::class)->name('events.versions.reports.registration-cards');
        Route::get('/events/versions/{version}/reports/registration-cards/export.pdf', RegistrationCardsPdfController::class)->name('events.versions.reports.registration-cards.pdf');
    });

    Route::get('/settings/profile', Profile::class)->name('settings.profile');
    Route::get('/settings/password', Password::class)->name('settings.password');
});
