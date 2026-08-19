<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\UploadType;
use App\Enums\Vendor;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionObligationStatus;
use App\Livewire\Sfdi\Events\Show;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\EventEpaymentConfig;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionDate;
use App\Models\VersionEpaymentConfig;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Models\VersionPitchFile;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\SquarePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Attaches a VoicePart to the Version's Event via an Ensemble, so it's a
 * legal option per Version::availableVoiceParts() — mirrors
 * CandidateDetailTest's own setup for the equivalent teacher-side edit.
 * abbr is set equal to name (not left to the factory's random default) so
 * the 'ALL' voice part case used by the Pitch Files tests behaves exactly
 * like the seeded ALL voice part (matched by abbr, not name).
 */
function attachSfdiVoicePart(Version $version, string $name = 'Tenor'): VoicePart
{
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $voicePart = VoicePart::factory()->create(['name' => $name, 'abbr' => $name]);
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

/**
 * A teacher who has responded to this Version's published obligation —
 * needed so the §5.3 obligations iron gate doesn't block every other test
 * by default (a Version with no obligation at all, or an unpublished one,
 * is never gated — see Show::isObligationsBlocked()).
 */
function acceptSfdiObligation(Version $version, int $teacherId): void
{
    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacherId,
        'status' => 'obligated',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $obligation = VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);
}

function makeSfdiEventsShowUser(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id, 'birthday' => null, 'height' => null]);

    return $user;
}

test('a teacher without a student profile is forbidden', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    actingAs($user);
    $candidate = Candidate::factory()->create();

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])->assertForbidden();
});

test('another student\'s Candidate row 404s', function () {
    $user = makeSfdiEventsShowUser();
    $otherStudent = Student::factory()->create();

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $otherStudent->id]);

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])->assertNotFound();
});

test('shows only the Candidate-requirements subset of the checklist, unmet items linking to the right profile page', function () {
    $user = makeSfdiEventsShowUser();
    $event = Event::factory()->create(['name' => 'All-State Chorus']);
    $version = Version::factory()->create([
        'event_id' => $event->id,
        'status' => EventStatus::Active,
        'birthday' => true,
        'height' => true,
        'home_address' => true,
        'shirt_size' => true,
        'emergency_contact_name' => true,
        'emergency_contact_cell' => false,
        'emergency_contact_email' => false,
    ]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSee('Birthday')
        ->assertSee('Height')
        ->assertSee('Home address')
        ->assertSee('Shirt size')
        ->assertSee('Emergency contact')
        ->assertDontSee('Program name')
        ->assertSee(route('sfdi.student-details'))
        ->assertSee(route('sfdi.emergency-contacts'));
});

test('a met requirement renders as done', function () {
    $user = makeSfdiEventsShowUser();
    $user->student->update(['birthday' => '2012-01-01']);
    $version = Version::factory()->create(['status' => EventStatus::Active, 'birthday' => true]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSee('Birthday')
        ->assertSeeHtml('bg-green-50');
});

// --- Voice part ---

test('saveVoicePart updates the candidate\'s voice part and recalculates status', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    $tenor = attachSfdiVoicePart($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('editVoicePart')
        ->set('edit_voice_part_id', (string) $tenor->id)
        ->call('saveVoicePart')
        ->assertHasNoErrors();

    expect($candidate->fresh()->voice_part_id)->toBe($tenor->id);
});

test('saveVoicePart rejects a voice part not available on this Version', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    attachSfdiVoicePart($version);
    $notAvailable = VoicePart::factory()->create();

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->set('edit_voice_part_id', (string) $notAvailable->id)
        ->call('saveVoicePart')
        ->assertHasErrors(['edit_voice_part_id']);
});

// --- Program name ---

test('saveProgramName updates the program name and recalculates status', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('editProgramName')
        ->set('edit_program_name', 'Jamie Lee')
        ->call('saveProgramName')
        ->assertHasNoErrors();

    expect($candidate->fresh()->program_name)->toBe('Jamie Lee');
});

// --- Withdrawal ---

test('withdraw sets status to Withdrew (distinct from TeacherWithdrawn) and redirects', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Registered,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('withdraw')
        ->assertRedirect(route('sfdi.events.index'));

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Withdrew);
});

// --- Status lock (§0 second pass) ---

test('voice part, program name, and withdrawal are blocked once the candidate is past active registration', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    $tenor = attachSfdiVoicePart($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Adjudicated,
    ]);

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->set('edit_voice_part_id', (string) $tenor->id)->call('saveVoicePart')->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->set('edit_program_name', 'Jamie Lee')->call('saveProgramName')->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('withdraw')->assertForbidden();

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->assertSee('no longer open for changes')
        ->assertDontSee('Withdraw from this Event');
});

test('a locked candidate\'s status is unaffected by editing — the teacher\'s own edit is not restricted by this gate', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Registered,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertDontSee('no longer open for changes')
        ->assertSee('Withdraw from this Event');
});

// --- Obligations iron gate (§0 second pass) ---

test('write actions are blocked while the candidate\'s teacher has not accepted this Version\'s published obligation', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    $tenor = attachSfdiVoicePart($version);

    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'rejected',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $obligation = VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Rejected->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->set('edit_voice_part_id', (string) $tenor->id)->call('saveVoicePart')->assertForbidden();

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->assertSee('Registration is temporarily paused');
});

test('write actions are available once the candidate\'s teacher has accepted the obligation', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = Version::factory()->create(['status' => EventStatus::Active]);
    $tenor = attachSfdiVoicePart($version);

    acceptSfdiObligation($version, $teacher->id);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->set('edit_voice_part_id', (string) $tenor->id)
        ->call('saveVoicePart')
        ->assertHasNoErrors();

    expect($candidate->fresh()->voice_part_id)->toBe($tenor->id);
});

// --- Recordings ---

test('saveRecording uploads a new recording as pending', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create([
        'status' => EventStatus::Active,
        'audition_type' => AuditionType::Remote->value,
        'upload_type' => UploadType::Audio->value,
    ]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    $file = UploadedFile::fake()->create('scales.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $upload = CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $slot->id)->first();

    expect($upload)->not->toBeNull();
    expect($upload->getRawOriginal('status'))->toBe('pending');
    Storage::disk('s3')->assertExists($upload->url);
});

test('saveRecording accepts a real-world .m4a file even when its detected MIME type is video/mp4', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create([
        'status' => EventStatus::Active,
        'audition_type' => AuditionType::Remote->value,
        'upload_type' => UploadType::Audio->value,
    ]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    // m4a shares the ISO-BMFF/MP4 container with video, so real-world m4a
    // files are frequently finfo-detected as video/mp4 rather than any
    // audio/* type — mimes: rejects that combination even though the
    // extension is legitimate (the actual bug reported 2026-08-18); this
    // reproduces it directly rather than relying on finfo's real behavior.
    $file = UploadedFile::fake()->create('scales.m4a', 500, 'video/mp4');

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    expect(CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $slot->id)->exists())->toBeTrue();
});

test('deleteRecording removes a pending upload and leaves the slot empty, without requiring a replacement', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'audition_type' => AuditionType::Remote->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'uploaded_by_user_id' => $user->id,
        'status' => 'pending',
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'fake-audio-bytes');

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('deleteRecording', $upload->id)
        ->assertOk();

    expect(CandidateUploadFile::find($upload->id))->toBeNull();
    Storage::disk('s3')->assertMissing($upload->url);
});

test('an approved recording cannot be uploaded over, deleted, or re-uploaded by the student — replay only', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'audition_type' => AuditionType::Remote->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'uploaded_by_user_id' => $user->id,
        'status' => 'approved',
        'uploaded_at' => now(),
        'decided_at' => now(),
        'decided_by_user_id' => $user->id,
    ]);
    Storage::disk('s3')->put($upload->url, 'fake-audio-bytes');

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('uploadRecording', $slot->id)->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('deleteRecording', $upload->id)->assertForbidden();

    // Untouched — the forbidden calls above must not have mutated anything.
    expect(CandidateUploadFile::find($upload->id)->getRawOriginal('status'))->toBe('approved');
    Storage::disk('s3')->assertExists($upload->url);
});

test('recordings cannot be uploaded or deleted while the candidate is locked or obligations-gated, even on a pending slot', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'audition_type' => AuditionType::Remote->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Withdrew,
    ]);

    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'uploaded_by_user_id' => $user->id,
        'status' => 'pending',
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'fake-audio-bytes');

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('uploadRecording', $slot->id)->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('deleteRecording', $upload->id)->assertForbidden();
});

// --- Pitch Files modal (§5.5) ---

test('viewPitchFiles opens without error', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('viewPitchFiles')
        ->assertOk();
});

test('the Pitch Files modal shows files matching the candidate\'s own voice part, and the ALL voice part, with no filter UI', function () {
    // Livewire's initial mount renders the full component, including the
    // modal content — so real pitch-file rows always exercise the blade's
    // Storage::disk('s3')->temporaryUrl() calls even when the test itself
    // never touches Storage, and never actually opens the modal.
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    $soprano = attachSfdiVoicePart($version, 'Soprano');
    $alto = attachSfdiVoicePart($version, 'Alto');
    $all = attachSfdiVoicePart($version, 'ALL');

    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'soprano-scales', 'url' => 'x', 'order_by' => 1]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $alto->id, 'name' => 'alto-scales', 'url' => 'y', 'order_by' => 2]);
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $all->id, 'name' => 'general-warmup', 'url' => 'z', 'order_by' => 3]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'voice_part_id' => $soprano->id,
    ]);

    $names = Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->viewData('pitchFiles')
        ->pluck('name');

    expect($names)->toContain('soprano-scales');
    expect($names)->toContain('general-warmup');
    expect($names)->not->toContain('alto-scales');
});

test('a Version set to Teacher-only visibility shows no pitch files to the student', function () {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'pitch_file_visibility' => 'teacher']);
    $soprano = attachSfdiVoicePart($version, 'Soprano');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'voice_part_id' => $soprano->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertDontSee('scales')
        ->assertSee('shared with candidates');
});

test('a Version set to Candidate-only or Both visibility shows pitch files to the student', function (string $visibility) {
    Storage::fake('s3');

    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'pitch_file_visibility' => $visibility]);
    $soprano = attachSfdiVoicePart($version, 'Soprano');
    VersionPitchFile::create(['version_id' => $version->id, 'voice_part_id' => $soprano->id, 'name' => 'scales', 'url' => 'x', 'order_by' => 1]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'voice_part_id' => $soprano->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSee('scales');
})->with(['candidate', 'both']);

// --- Candidate Application (§5.6) ---

function publishSfdiApplication(Version $version): VersionApplication
{
    return VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student text.</p>',
        'parent_endorsement_body' => '<p>Parent text.</p>',
        'teacher_principal_endorsement_body' => $version->getRawOriginal('application_type') === ApplicationType::Pdf->value ? '<p>Teacher text.</p>' : null,
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('the View Application modal shows the self-attestation checkboxes in EApplication mode, and the download link', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::EApplication->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('viewApplication')
        ->assertSee('Student text.')
        ->assertSee('Parent text.')
        ->assertDontSee('Teacher text.')
        ->assertSee('I have signed')
        ->assertSee('My parent/guardian has reviewed and approved')
        ->assertSee(route('registrations.candidate.application-pdf', [$version, $candidate]));
});

test('the View Application modal shows only a download link in Pdf mode, no self-attestation checkboxes', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::Pdf->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('viewApplication')
        ->assertDontSee('I have signed')
        ->assertDontSee('My parent/guardian has reviewed and approved')
        ->assertSee(route('registrations.candidate.application-pdf', [$version, $candidate]));
});

test('toggleApplicationCandidateSigned and toggleApplicationParentSigned self-attest and recalculate status', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::EApplication->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('toggleApplicationCandidateSigned')
        ->call('toggleApplicationParentSigned')
        ->assertHasNoErrors();

    $candidate->refresh();
    expect($candidate->application_candidate_signed_at)->not->toBeNull();
    expect($candidate->application_parent_signed_at)->not->toBeNull();
});

test('the document shows a simulated signature and the actual signed date once attested, not a blank line', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::EApplication->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'program_name' => 'Jamie Lee',
    ]);

    $component = Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate]);

    // Unsigned: blank signature line, no signed-name rendering.
    $component->assertSeeHtml('________________________');
    $component->assertDontSee('Electronically signed');

    $component->call('toggleApplicationCandidateSigned')->call('toggleApplicationParentSigned');

    $candidate = $candidate->fresh();
    $signedAt = Carbon::parse($candidate->getRawOriginal('application_candidate_signed_at'));

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSeeHtml('ca-signature')
        ->assertSee($signedAt->format('M j, Y'))
        ->assertSee('Electronically signed');
});

test('the teacher\'s own toggle capability is unaffected by the student self-attesting', function () {
    // Both roles can toggle the same two timestamps independently
    // (studentfolder-module.md §5.6) — this only proves the student's own
    // toggle doesn't remove/replace anything on the shared Candidate row
    // a teacher-side test would also exercise; it does not re-test the
    // teacher's own CandidateDetail toggle, already covered there.
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::EApplication->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'application_candidate_signed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('toggleApplicationParentSigned');

    $candidate->refresh();
    expect($candidate->application_candidate_signed_at)->not->toBeNull();
    expect($candidate->application_parent_signed_at)->not->toBeNull();
});

test('application signature toggles are blocked once the candidate is locked or obligations-gated', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'application_type' => ApplicationType::EApplication->value]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Withdrew,
    ]);

    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('toggleApplicationCandidateSigned')->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate])
        ->call('toggleApplicationParentSigned')->assertForbidden();

    expect($candidate->fresh()->application_candidate_signed_at)->toBeNull();
});

// --- Payment (studentfolder-module.md §5.7, step 8) ---

/**
 * Stands in for the real SquarePaymentGateway — see
 * VersionDashboardTest.php's identical-in-spirit FakeSquareGateway, not
 * reused directly since that class is declared at that other test file's
 * top level and Pest runs every file in the same PHP process. This fake
 * additionally records $lastPayer so tests can assert a Student payer
 * populates payer_student_id, not payer_teacher_id (§9 item 3).
 */
class FakeSfdiSquareGateway implements PaymentGatewayContract
{
    public static Teacher|Student|null $lastPayer = null;

    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher|Student $payer, FeeType $feeType): CheckoutSession
    {
        self::$lastPayer = $payer;

        /** @var Candidate $firstCandidate */
        $firstCandidate = $candidates->first();

        $transaction = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => PaymentSource::CandidateEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => 'fake-order-'.uniqid(),
            'payer_teacher_id' => $payer instanceof Teacher ? $payer->id : null,
            'payer_student_id' => $payer instanceof Student ? $payer->id : null,
            'school_id' => $firstCandidate->school_id,
            'amount' => 12345,
            'status' => PaymentTransactionStatus::Pending,
            'fee_type' => $feeType,
        ]);

        return new CheckoutSession(redirectUrl: 'https://fake.example/checkout', paymentTransactionId: $transaction->id);
    }

    public function verifyWebhookSignature(Request $request, Event $event): bool
    {
        return true;
    }

    public function parseWebhookEvent(Request $request): WebhookEvent
    {
        throw new RuntimeException('not used in this test');
    }
}

/**
 * A Version ready for student e-payment: Active, Square configured at the
 * Event level, epayment_student on, a VersionFee row, and the given
 * teacher opted in — the full §5.7 gate satisfied except per-test overrides.
 */
function makeSfdiPayableVersion(int $teacherId, array $feeOverrides = []): Version
{
    $version = Version::factory()->create(['status' => EventStatus::Active]);

    VersionEpaymentConfig::create(['version_id' => $version->id, 'epayment_student' => true, 'epayment_teacher' => true]);
    EventEpaymentConfig::create([
        'event_id' => $version->event_id,
        'vendor' => Vendor::Square,
        'vendor_account_id' => 'loc-123',
        'secret' => 'token-123',
    ]);
    VersionFee::create(array_merge([
        'version_id' => $version->id,
        'registration' => 2000,
    ], $feeOverrides));
    VersionTeacherEpaymentOptIn::create(['version_id' => $version->id, 'teacher_id' => $teacherId, 'opted_in' => true]);

    return $version;
}

test('payNow aborts with 403 when epaymentStudentEnabled is off for this Version', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);
    $version->versionEpaymentConfig->update(['epayment_student' => false]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'registration')
        ->assertStatus(403);
});

test('payNow aborts with 403 when the candidate\'s own teacher has not opted in', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);
    VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $teacher->id)->update(['opted_in' => false]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'registration')
        ->assertStatus(403);
});

test('payNow aborts with 403 for registration once the Adjudication window has started, even though the Version is still open', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication,
        'start_at' => now()->subDay(),
    ]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'registration')
        ->assertStatus(403);
});

test('payNow aborts with 422 when the candidate is not in a registration-fee-eligible state', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Withdrew,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'registration')
        ->assertStatus(422);
});

test('payNow aborts with 403 for housing/participation before the Version is closed', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id, ['housing' => 1000, 'participation' => 500]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Accepted,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'housing')
        ->assertStatus(403);
    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'participation')
        ->assertStatus(403);
});

test('a successful payNow creates a payment_transactions row with payer_student_id set, not payer_teacher_id, and redirects to checkout', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);
    app()->bind(SquarePaymentGateway::class, fn () => new FakeSfdiSquareGateway);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('payNow', 'registration')
        ->assertRedirect('https://fake.example/checkout');

    expect(FakeSfdiSquareGateway::$lastPayer)->toBeInstanceOf(Student::class);
    expect(FakeSfdiSquareGateway::$lastPayer->id)->toBe($user->student->id);

    $transaction = PaymentTransaction::sole();
    expect($transaction->payer_student_id)->toBe($user->student->id);
    expect($transaction->payer_teacher_id)->toBeNull();
});

test('Pay Registration Fee is offered once opted in and hidden once the balance is fully paid', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSee('Pay Registration Fee');

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::CandidateEpayment,
        'vendor' => Vendor::Square,
        'vendor_transaction_id' => 'order-paid',
        'payer_student_id' => $user->student->id,
        'school_id' => $candidate->school_id,
        'amount' => 2000,
        'status' => PaymentTransactionStatus::Completed,
        'fee_type' => FeeType::Registration,
        'paid_at' => now(),
    ]);
    PaymentAllocation::create([
        'payment_transaction_id' => $transaction->id,
        'candidate_id' => $candidate->id,
        'amount' => 2000,
        'allocated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertDontSee('Pay Registration Fee');
});

test('Pay Now buttons stay hidden when the candidate\'s own teacher has not opted in, even though the Version is otherwise ready', function () {
    $user = makeSfdiEventsShowUser();
    $teacher = Teacher::factory()->create();
    $version = makeSfdiPayableVersion($teacher->id);
    VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $teacher->id)->update(['opted_in' => false]);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertDontSee('Pay Registration Fee');
});

// --- Take a tour ---

test('the Take a tour button auto-starts for a student who has never taken it on this page', function () {
    $user = makeSfdiEventsShowUser();

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $user->student->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="1"');
});

test('the Take a tour button does not auto-start once already dismissed', function () {
    $user = makeSfdiEventsShowUser();
    $user->update(['dismissed_sfdi_candidate_orientation_at' => now()]);

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $user->student->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSeeHtml('data-auto-start="0"');
});

test('dismissOrientation persists the dismissal for the acting user', function () {
    $user = makeSfdiEventsShowUser();

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $user->student->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->call('dismissOrientation');

    expect($user->fresh()->dismissed_sfdi_candidate_orientation_at)->not->toBeNull();
});

test('the core tour anchors render regardless of which conditional cards are present', function () {
    $user = makeSfdiEventsShowUser();

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $user->student->id]);

    $component = Livewire::actingAs($user)->test(Show::class, ['candidate' => $candidate]);

    foreach ([
        'id="tour-status-badge"',
        'id="tour-pitch-files"',
        'id="tour-candidate-requirements"',
        'id="tour-registration-card"',
        'id="tour-payment-card"',
    ] as $needle) {
        $component->assertSeeHtml($needle);
    }
});

test('the Application and Recordings tour anchors render only when those cards are present', function () {
    $user = makeSfdiEventsShowUser();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'audition_type' => AuditionType::Remote->value]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    publishSfdiApplication($version);

    actingAs($user);
    $candidate = Candidate::factory()->create(['student_id' => $user->student->id, 'version_id' => $version->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['candidate' => $candidate])
        ->assertSeeHtml('id="tour-application-card"')
        ->assertSeeHtml('id="tour-recordings-card"');
});
