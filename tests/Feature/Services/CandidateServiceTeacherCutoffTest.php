<?php

declare(strict_types=1);

use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Livewire\Registrations\CandidateDetail;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Recording;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by Auth::id().
    actingAs(User::factory()->create());
});

function cutoffVersion(?string $finalTeacherChanges, ?string $postmark = null, array $attributes = []): Version
{
    $version = Version::factory()->create($attributes);

    if ($finalTeacherChanges !== null) {
        VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::FinalTeacherChanges, 'start_at' => $finalTeacherChanges]);
    }
    if ($postmark !== null) {
        VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::PostmarkDeadline, 'start_at' => $postmark]);
    }

    return $version;
}

function cutoffCandidate(Version $version, CandidateStatus $status, array $attributes = []): Candidate
{
    return Candidate::factory()->create(['version_id' => $version->id, 'status' => $status, ...$attributes]);
}

test('the cut-off is Final Teacher Changes, falling back to the Postmark Deadline', function () {
    expect(cutoffVersion('2026-11-15 04:59:00', '2026-11-17 12:08:00')->teacherChangesCutoff()?->toDateTimeString())->toBe('2026-11-15 04:59:00')
        ->and(cutoffVersion(null, '2026-11-17 12:08:00')->teacherChangesCutoff()?->toDateTimeString())->toBe('2026-11-17 12:08:00')
        ->and(cutoffVersion(null)->teacherChangesCutoff())->toBeNull()
        ->and(cutoffVersion(null)->registrationClosedToTeachers())->toBeFalse();
});

test('after the cut-off, an Eligible or Pending candidate with a complete checklist holds at Pending', function (CandidateStatus $from) {
    $version = cutoffVersion(now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, $from);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Pending);
})->with([CandidateStatus::Eligible, CandidateStatus::Pending]);

test('before the cut-off, a complete checklist still registers the candidate', function () {
    $version = cutoffVersion(now()->addDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::Pending);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('a future Final Teacher Changes date wins over a passed Postmark Deadline', function () {
    $version = cutoffVersion(now()->addDay()->toDateTimeString(), now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::Pending);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('the Postmark Deadline is the cut-off when there is no Final Teacher Changes date', function () {
    $version = cutoffVersion(null, now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::Pending);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Pending);
});

test('a candidate already Registered stays Registered after the cut-off', function () {
    $version = cutoffVersion(now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::Registered);

    (new CandidateService)->recalculateStatus($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('reinstating a withdrawn candidate after the cut-off does not register them', function () {
    $version = cutoffVersion(now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::TeacherWithdrawn);

    (new CandidateService)->reinstate($candidate, []);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Pending);
});

test('Event Manager tools can still register after the cut-off', function () {
    $version = cutoffVersion(now()->subDay()->toDateTimeString());
    $candidate = cutoffCandidate($version, CandidateStatus::Pending);

    (new CandidateService)->recalculateStatus($candidate, [], ignoreTeacherCutoff: true);

    expect($candidate->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('a teacher completing the checklist after the cut-off sees the candidate held, with an explanation', function () {
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id, 'onboarding_completed_at' => now()]);
    $version = cutoffVersion('2026-11-15 04:59:00', null, ['emergency_contact_name' => false]);
    Carbon::setTestNow('2026-11-16 12:00:00');

    actingAs($teacher->user);
    $candidate = cutoffCandidate($version, CandidateStatus::Eligible, ['teacher_id' => $teacher->id, 'program_name' => '']);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('program_name', 'Jane Smith')
        ->call('saveProgramName')
        ->assertHasNoErrors()
        ->assertSee('Registration closed Saturday, November 14 at 11:59 PM')
        ->assertSee('finished after the deadline for teacher changes');

    expect($candidate->refresh()->program_name)->toBe('Jane Smith')
        ->and($candidate->status)->toBe(CandidateStatus::Pending);

    Carbon::setTestNow();
});

test('after the cut-off a teacher can still edit student details but not the voice part', function () {
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id, 'onboarding_completed_at' => now()]);
    $version = cutoffVersion(now()->subDay()->toDateTimeString(), null, ['emergency_contact_name' => false]);
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    [$alto, $tenor] = [VoicePart::factory()->create(['name' => 'Alto']), VoicePart::factory()->create(['name' => 'Tenor'])];
    $ensemble->voiceParts()->attach([$alto->id, $tenor->id]);

    actingAs($teacher->user);
    $candidate = cutoffCandidate($version, CandidateStatus::Registered, ['teacher_id' => $teacher->id, 'voice_part_id' => $alto->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editStudent')
        ->assertSee("the voice part can't be changed", false)
        ->set('edit_first_name', 'Jamie')
        ->set('edit_voice_part_id', (string) $tenor->id) // a crafted request
        ->call('saveStudent')
        ->assertHasNoErrors();

    $candidate->refresh();
    expect($candidate->student->user->fresh()->first_name)->toBe('Jamie')
        ->and($candidate->voice_part_id)->toBe($alto->id)
        ->and($candidate->status)->toBe(CandidateStatus::Registered);
});

test('before the cut-off a teacher can still change the voice part', function () {
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id, 'onboarding_completed_at' => now()]);
    $version = cutoffVersion(now()->addDay()->toDateTimeString(), null, ['emergency_contact_name' => false]);
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    [$alto, $tenor] = [VoicePart::factory()->create(['name' => 'Alto']), VoicePart::factory()->create(['name' => 'Tenor'])];
    $ensemble->voiceParts()->attach([$alto->id, $tenor->id]);

    actingAs($teacher->user);
    $candidate = cutoffCandidate($version, CandidateStatus::Registered, ['teacher_id' => $teacher->id, 'voice_part_id' => $alto->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editStudent')
        ->assertDontSee("the voice part can't be changed", false)
        ->set('edit_voice_part_id', (string) $tenor->id)
        ->call('saveStudent')
        ->assertHasNoErrors();

    expect($candidate->refresh()->voice_part_id)->toBe($tenor->id);
});

/**
 * A remote-audition Candidate with one pending Scales upload.
 *
 * @return array{teacher: Teacher, version: Version, candidate: Candidate, upload: CandidateUploadFile}
 */
function cutoffRecordingScenario(string $finalTeacherChanges): array
{
    Storage::fake('s3');
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id, 'onboarding_completed_at' => now()]);
    $version = cutoffVersion($finalTeacherChanges, null, ['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value, 'emergency_contact_name' => false]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = cutoffCandidate($version, CandidateStatus::Pending, ['teacher_id' => $teacher->id]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'uploaded_by_user_id' => $teacher->user->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    return ['teacher' => $teacher, 'version' => $version, 'candidate' => $candidate, 'upload' => $upload];
}

test('after the cut-off a teacher sees recordings read-only and can\'t upload, approve or reject them', function () {
    ['teacher' => $teacher, 'version' => $version, 'candidate' => $candidate, 'upload' => $upload] = cutoffRecordingScenario(now()->subDay()->toDateTimeString());

    $page = fn () => Livewire::actingAs($teacher->user)->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate]);

    $page()->assertSee("recordings can't be uploaded, replaced, approved or removed", false)
        ->assertDontSeeHtml('wire:click="reviewRecording(')
        ->assertDontSeeHtml('wire:click="uploadRecording(');

    // A forbidden call ends the test component, so each gets a fresh one.
    $page()->call('approveRecording', $upload->id)->assertForbidden();
    $page()->call('rejectRecording', $upload->id)->assertForbidden();
    $page()->call('uploadRecording', $upload->version_upload_file_id)->assertForbidden();

    expect($upload->fresh()->getRawOriginal('status'))->toBe(CandidateUploadStatus::Pending->value)
        ->and(Recording::where('candidate_id', $candidate->id)->exists())->toBeFalse()
        ->and($candidate->fresh()->status)->toBe(CandidateStatus::Pending);
});

test('after the cut-off a teacher can\'t save a new recording either', function () {
    ['teacher' => $teacher, 'version' => $version, 'candidate' => $candidate, 'upload' => $upload] = cutoffRecordingScenario(now()->subDay()->toDateTimeString());

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('uploadingVersionUploadFileId', $upload->version_upload_file_id)
        ->set('newRecordingFile', UploadedFile::fake()->create('scales.mp3', 100))
        ->call('saveRecording')
        ->assertForbidden();

    expect($upload->fresh()->url)->toBe($upload->url);
});

test('before the cut-off a teacher can still approve recordings', function () {
    ['teacher' => $teacher, 'version' => $version, 'candidate' => $candidate, 'upload' => $upload] = cutoffRecordingScenario(now()->addDay()->toDateTimeString());

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee("recordings can't be uploaded", false)
        ->assertSeeHtml('wire:click="reviewRecording(')
        ->call('approveRecording', $upload->id)
        ->assertOk();

    expect($upload->fresh()->getRawOriginal('status'))->toBe(CandidateUploadStatus::Approved->value);
});
