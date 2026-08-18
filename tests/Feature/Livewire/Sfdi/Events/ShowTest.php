<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\ObligationDecision;
use App\Enums\UploadType;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionObligationStatus;
use App\Livewire\Sfdi\Events\Show;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Models\VersionPitchFile;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
