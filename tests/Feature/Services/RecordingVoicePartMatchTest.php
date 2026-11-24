<?php

declare(strict_types=1);

use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\UploadType;
use App\Enums\VoicePartRecordingDecision;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Livewire\Founder\VoicePartAudit;
use App\Livewire\Registrations\CandidateDetail;
use App\Livewire\Sfdi\Events\Show;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Recording;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionPitchFile;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\VoicePartChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('s3'));

/**
 * A remote Version offering Alto I and Alto II, with one Scales slot and one
 * Alto I candidate.
 *
 * @return array{version: Version, teacher: Teacher, student: Student, candidate: Candidate, slot: VersionUploadFile, alto1: VoicePart, alto2: VoicePart}
 */
function recordingMatchFixture(bool $confirmed = true): array
{
    $version = Version::factory()->create([
        'emergency_contact_name' => false, 'birthday' => false, 'height' => false, 'home_address' => false, 'shirt_size' => false,
        'audition_type' => AuditionType::Remote->value,
        'upload_type' => UploadType::Audio->value,
        'pitch_file_visibility' => PitchFileVisibility::Both->value,
    ]);
    $alto1 = VoicePart::factory()->create(['name' => 'Alto I', 'abbr' => 'A1']);
    $alto2 = VoicePart::factory()->create(['name' => 'Alto II', 'abbr' => 'A2']);
    Ensemble::factory()->create(['event_id' => $version->event_id])->voiceParts()->attach([$alto1->id, $alto2->id]);

    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id]);
    $student = Student::factory()->create(['user_id' => User::factory()->create()->id]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    foreach ([$alto1, $alto2] as $part) {
        VersionPitchFile::create([
            'version_id' => $version->id, 'voice_part_id' => $part->id, 'name' => 'Scales',
            'description' => "{$part->name} reference", 'url' => "pitchFiles/{$part->id}.mp3", 'order_by' => 1,
        ]);
    }

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'student_id' => $student->id,
        'voice_part_id' => $alto1->id,
        'voice_part_confirmed_at' => $confirmed ? now() : null,
        'status' => CandidateStatus::Pending,
    ]);

    return compact('version', 'teacher', 'student', 'candidate', 'slot', 'alto1', 'alto2');
}

function recordingMatchUpload(array $f, string $status = 'pending', ?int $stamp = null): CandidateUploadFile
{
    $url = "candidateUploads/{$f['candidate']->id}/scales.mp3";
    Storage::disk('s3')->put($url, 'audio');

    $upload = CandidateUploadFile::create([
        'candidate_id' => $f['candidate']->id, 'version_upload_file_id' => $f['slot']->id,
        'voice_part_id' => $stamp, 'uploaded_by_user_id' => $f['teacher']->user_id,
        'url' => $url, 'status' => $status, 'uploaded_at' => now(),
    ]);

    if ($status === 'approved') {
        Recording::create([
            'version_id' => $f['version']->id, 'candidate_id' => $f['candidate']->id, 'voice_part_id' => $stamp,
            'file_type' => 'Scales', 'uploaded_by' => $f['teacher']->user_id,
            'approved_at' => now()->subDay(), 'approved_by' => User::factory()->create()->id, 'url' => $url,
        ]);
    }

    return $upload;
}

function recordingMatchTeacherPage(array $f)
{
    return Livewire::actingAs($f['teacher']->user)->test(CandidateDetail::class, ['version' => $f['version'], 'candidate' => $f['candidate']]);
}

function recordingMatchStudentPage(array $f)
{
    return Livewire::actingAs($f['student']->user)->test(Show::class, ['candidate' => $f['candidate']]);
}

test('an unconfirmed voice part blocks uploads and pitch files until the student confirms it', function () {
    $f = recordingMatchFixture(confirmed: false);

    $page = recordingMatchStudentPage($f)
        ->assertSee('Confirm Voice Part')
        ->assertDontSee('Alto I reference')
        ->call('uploadRecording', $f['slot']->id)
        ->assertSet('uploadingVersionUploadFileId', null);

    $page->call('confirmVoicePart', $f['alto1']->id)
        ->assertSee('Alto I reference')
        ->assertDontSee('Alto II reference')
        ->call('uploadRecording', $f['slot']->id)
        ->assertSet('uploadingVersionUploadFileId', $f['slot']->id);

    expect($f['candidate']->fresh()->voice_part_confirmed_at)->not->toBeNull();
});

test('saving a recording is refused while the voice part is unconfirmed', function () {
    $f = recordingMatchFixture(confirmed: false);

    recordingMatchStudentPage($f)->call('saveRecording')->assertForbidden();
    recordingMatchTeacherPage($f)->call('saveRecording')->assertForbidden();
});

test('a teacher can confirm an unconfirmed voice part, but not a part that is no longer current', function () {
    $f = recordingMatchFixture(confirmed: false);

    recordingMatchTeacherPage($f)->call('confirmVoicePart', $f['alto2']->id)->assertStatus(409);
    recordingMatchTeacherPage($f)->call('confirmVoicePart', $f['alto1']->id)->assertHasNoErrors();

    expect($f['candidate']->fresh()->needsVoicePartConfirmation())->toBeFalse();
});

test('a candidate who already has recordings is not asked to confirm', function () {
    $f = recordingMatchFixture(confirmed: false);
    recordingMatchUpload($f);

    expect($f['candidate']->needsVoicePartConfirmation())->toBeFalse();
});

test('an upload names the part, requires the attestation and is stamped', function (string $portal) {
    $f = recordingMatchFixture();
    $page = $portal === 'teacher' ? recordingMatchTeacherPage($f) : recordingMatchStudentPage($f);

    $page->call('uploadRecording', $f['slot']->id)
        ->assertSee('Upload Alto I – Scales')
        ->assertSee('This is the Alto I part')
        ->set('newRecordingFile', UploadedFile::fake()->create('scales.mp3', 100, 'audio/mpeg'))
        ->call('saveRecording')
        ->assertHasErrors('recording_part_attested')
        ->set('recording_part_attested', true)
        ->call('saveRecording')
        ->assertHasNoErrors();

    expect(CandidateUploadFile::where('candidate_id', $f['candidate']->id)->sole()->voice_part_id)->toBe($f['alto1']->id);
})->with(['teacher', 'student']);

test('an upload is refused when the part changed after the modal opened', function (string $portal) {
    $f = recordingMatchFixture();
    $page = $portal === 'teacher' ? recordingMatchTeacherPage($f) : recordingMatchStudentPage($f);

    $page->call('uploadRecording', $f['slot']->id);
    Candidate::whereKey($f['candidate']->id)->update(['voice_part_id' => $f['alto2']->id]);

    $page->set('newRecordingFile', UploadedFile::fake()->create('scales.mp3', 100, 'audio/mpeg'))
        ->set('recording_part_attested', true)
        ->call('saveRecording')
        ->assertHasErrors('newRecordingFile');

    expect(CandidateUploadFile::where('candidate_id', $f['candidate']->id)->count())->toBe(0);
})->with(['teacher', 'student']);

test('the approval modal shows the matching pitch file and approval stamps the recording', function () {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f, stamp: $f['alto2']->id);

    recordingMatchTeacherPage($f)
        ->assertSee('Recorded as Alto II')
        ->call('reviewRecording', $upload->id)
        ->assertSee('Alto I reference')
        ->assertDontSee('Alto II reference')
        ->assertSee('This was uploaded as')
        ->call('approveRecording', $upload->id);

    expect($upload->fresh()->voice_part_id)->toBe($f['alto1']->id)
        ->and(Recording::where('candidate_id', $f['candidate']->id)->sole()->voice_part_id)->toBe($f['alto1']->id);
});

test('approval is refused when the recording or part changed while it was being reviewed', function (array $candidateChange, array $uploadChange) {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f);

    $page = recordingMatchTeacherPage($f)->call('reviewRecording', $upload->id);

    if ($candidateChange !== []) {
        Candidate::whereKey($f['candidate']->id)->update(['voice_part_id' => $f['alto2']->id]);
    }
    if ($uploadChange !== []) {
        $upload->update($uploadChange);
    }

    $page->call('approveRecording', $upload->id);

    expect($upload->fresh()->getRawOriginal('status'))->toBe('pending')
        ->and(Recording::where('candidate_id', $f['candidate']->id)->count())->toBe(0);
})->with([
    'part changed' => [['changed'], []],
    'file replaced' => [[], ['url' => 'candidateUploads/other.mp3']],
]);

test('a student changing parts keeps pending recordings as mismatched, which blocks registration', function () {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f);

    recordingMatchStudentPage($f)
        ->set('edit_voice_part_id', (string) $f['alto2']->id)
        ->call('saveVoicePart')
        ->assertHasNoErrors()
        ->assertSee('Recorded as Alto I');

    expect($upload->fresh()->voice_part_id)->toBe($f['alto1']->id)
        ->and($f['candidate']->fresh()->voice_part_id)->toBe($f['alto2']->id);

    // Even with the upload approved, the mismatch holds it below Registered.
    $upload->update(['status' => 'approved']);
    recordingMatchTeacherPage($f)->call('refreshStatus');
    expect($f['candidate']->fresh()->getRawOriginal('status'))->toBe('pending');

    $upload->update(['voice_part_id' => $f['alto2']->id]);
    recordingMatchTeacherPage($f)->call('refreshStatus');
    expect($f['candidate']->fresh()->getRawOriginal('status'))->toBe('registered');
});

test('an unstamped recording is unknown and does not block registration', function () {
    $f = recordingMatchFixture();
    recordingMatchUpload($f, 'approved');

    recordingMatchTeacherPage($f)->call('refreshStatus');

    expect($f['candidate']->fresh()->getRawOriginal('status'))->toBe('registered');
});

test('a student cannot change parts once a recording is approved', function () {
    $f = recordingMatchFixture();
    recordingMatchUpload($f, 'approved', $f['alto1']->id);

    recordingMatchStudentPage($f)
        ->set('edit_voice_part_id', (string) $f['alto2']->id)
        ->call('saveVoicePart')
        ->assertHasErrors('edit_voice_part_id');

    expect($f['candidate']->fresh()->voice_part_id)->toBe($f['alto1']->id);
});

test('a teacher must answer for the recordings; yes re-stamps and re-records the approval', function () {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f, 'approved', $f['alto1']->id);

    recordingMatchTeacherPage($f)
        ->call('editStudent')
        ->set('edit_voice_part_id', (string) $f['alto2']->id)
        ->call('saveStudent')
        ->assertHasErrors('recording_decision')
        ->set('recording_decision', 'match')
        ->call('saveStudent')
        ->assertHasNoErrors();

    $recording = Recording::where('candidate_id', $f['candidate']->id)->sole();
    expect($f['candidate']->fresh()->voice_part_id)->toBe($f['alto2']->id)
        ->and($upload->fresh()->voice_part_id)->toBe($f['alto2']->id)
        ->and($upload->fresh()->decided_by_user_id)->toBe($f['teacher']->user_id)
        ->and($recording->voice_part_id)->toBe($f['alto2']->id)
        ->and($recording->approved_by)->toBe($f['teacher']->user_id);
});

test('a teacher answering no removes the recordings and their files', function () {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f, 'approved', $f['alto1']->id);

    recordingMatchTeacherPage($f)
        ->call('editStudent')
        ->set('edit_voice_part_id', (string) $f['alto2']->id)
        ->set('recording_decision', 'remove')
        ->call('saveStudent')
        ->assertHasNoErrors();

    expect(CandidateUploadFile::where('candidate_id', $f['candidate']->id)->count())->toBe(0)->and(Recording::where('candidate_id', $f['candidate']->id)->count())->toBe(0);
    Storage::disk('s3')->assertMissing($upload->url);
});

test('editing other student details neither asks about recordings nor confirms the part', function () {
    $f = recordingMatchFixture(confirmed: false);

    recordingMatchTeacherPage($f)->call('editStudent')->call('saveStudent')->assertHasNoErrors();

    expect($f['candidate']->fresh()->voice_part_confirmed_at)->toBeNull();
});

test('the audit asks before changing a part, and cancel changes nothing', function () {
    $f = recordingMatchFixture();
    $upload = recordingMatchUpload($f, 'approved', $f['alto1']->id);
    $founder = User::where('email', 'rick@mfrholdings.com')->first() ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);

    $page = Livewire::actingAs($founder)->test(VoicePartAudit::class)
        ->set('versionId', $f['version']->id)
        ->call('changeVoicePart', $f['candidate']->id, (string) $f['alto2']->id)
        ->assertSee('Do the recordings match Alto II?');
    expect($f['candidate']->fresh()->voice_part_id)->toBe($f['alto1']->id);

    $page->call('cancelVoicePartChange')->call('answerRecordings', 'match');
    expect($f['candidate']->fresh()->voice_part_id)->toBe($f['alto1']->id);

    $page->call('changeVoicePart', $f['candidate']->id, (string) $f['alto2']->id)->call('answerRecordings', 'match');
    expect($f['candidate']->fresh()->voice_part_id)->toBe($f['alto2']->id)
        ->and($upload->fresh()->voice_part_id)->toBe($f['alto2']->id);
});

test('the shared service refuses a part not offered, unless overridden', function () {
    $f = recordingMatchFixture();
    $other = VoicePart::factory()->create();
    $service = app(VoicePartChangeService::class);

    expect(fn () => $service->change($f['candidate'], $other, VoicePartRecordingDecision::Match))
        ->toThrow(VoicePartChangeRefusedException::class);

    $service->change($f['candidate'], $other, VoicePartRecordingDecision::Match, override: true);
    expect($f['candidate']->fresh()->voice_part_id)->toBe($other->id);
});
