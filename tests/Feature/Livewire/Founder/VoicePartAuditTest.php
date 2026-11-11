<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Livewire\Founder\VoicePartAudit;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\VoicePartAuditService;
use App\Support\VoicePartAuditRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeVoicePartAuditFounder(): User
{
    return User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);
}

/**
 * A Version whose one unrestricted Ensemble offers Soprano I, Soprano II and
 * Alto I. "Soprano" exists but is offered by no Ensemble — the legacy
 * mismatch shape.
 *
 * @return array{version: Version, teacher: Teacher, slot: VersionUploadFile, parts: array<string, VoicePart>}
 */
function voicePartAuditFixture(): array
{
    $version = Version::factory()->create();
    $parts = [];

    foreach (['Soprano' => 2, 'Soprano I' => 3, 'Soprano II' => 4, 'Alto I' => 6] as $name => $order) {
        $parts[$name] = VoicePart::factory()->create(['name' => $name, 'sort_order' => $order]);
    }

    Ensemble::factory()->create(['event_id' => $version->event_id])
        ->voiceParts()->attach([$parts['Soprano I']->id, $parts['Soprano II']->id, $parts['Alto I']->id]);

    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    return ['version' => $version, 'teacher' => $teacher, 'slot' => $slot, 'parts' => $parts];
}

function voicePartAuditCandidate(
    array $fixture,
    string $part,
    string $lastName,
    CandidateUploadStatus $uploadStatus = CandidateUploadStatus::Approved,
    CandidateStatus $status = CandidateStatus::Pending,
): Candidate {
    actingAs($fixture['teacher']->user);

    $candidate = Candidate::factory()->create([
        'version_id' => $fixture['version']->id,
        'teacher_id' => $fixture['teacher']->id,
        'voice_part_id' => $fixture['parts'][$part]->id,
        'status' => $status,
    ]);
    $candidate->student->user->update(['first_name' => 'Pat', 'last_name' => $lastName]);

    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $fixture['slot']->id,
        'url' => "candidateUploads/{$candidate->id}/scales.mp3",
        'original_filename' => "{$lastName} scales.mp3",
        'status' => $uploadStatus->value,
        'uploaded_at' => now(),
    ]);

    return $candidate;
}

function voicePartAuditAddScore(Candidate $candidate): void
{
    $category = ScoreCategory::create(['event_id' => $candidate->version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $candidate->version->event_id, 'version_id' => null, 'score_category_id' => $category->id,
        'description' => 'High Scale', 'abbreviation' => 'HS', 'best' => 5, 'worst' => 1,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1,
    ]);
    $room = VersionRoom::create(['version_id' => $candidate->version_id, 'name' => 'Room', 'order_by' => 1]);
    $judge = RoomJudge::create([
        'version_id' => $candidate->version_id, 'room_id' => $room->id, 'user_id' => User::factory()->create()->id,
        'judge_type' => JudgeType::HeadJudge, 'status' => JudgeStatus::Assigned,
    ]);

    Score::create([
        'version_id' => $candidate->version_id,
        'candidate_id' => $candidate->id,
        'student_id' => $candidate->student_id,
        'school_id' => $candidate->school_id,
        'score_category_id' => $category->id,
        'score_category_order_by' => 1,
        'score_factor_id' => $factor->id,
        'score_factor_order_by' => 1,
        'judge_id' => $judge->id,
        'judge_order_by' => 1,
        'voice_part_id' => $candidate->voice_part_id,
        'voice_part_order_by' => 1,
        'score' => 5,
    ]);
}

test('a non-founder cannot view the voice part audit page', function () {
    actingAs(User::factory()->create())->get(route('founder.voice-part-audit'))->assertNotFound();
});

test('the founder sees a version prompt before choosing a version', function () {
    actingAs(makeVoicePartAuditFounder())
        ->get(route('founder.voice-part-audit'))
        ->assertOk()
        ->assertSeeText('Voice Part Audit')
        ->assertSeeText('Select a version to list its candidates');
});

test('lists only candidates with an approved recording, with the approved files only', function () {
    $fixture = voicePartAuditFixture();
    $approved = voicePartAuditCandidate($fixture, 'Soprano I', 'Approvedson');
    voicePartAuditCandidate($fixture, 'Alto I', 'Pendingson', CandidateUploadStatus::Pending);

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $fixture['version']->id)
        ->assertSee((string) $approved->id)
        ->assertSee('Approvedson')
        ->assertSee('Approvedson scales.mp3')
        ->assertDontSee('Pendingson');
});

test('rows are sorted by voice part sort order, then student name', function () {
    $fixture = voicePartAuditFixture();
    voicePartAuditCandidate($fixture, 'Alto I', 'Aardvark');
    voicePartAuditCandidate($fixture, 'Soprano II', 'Zimmer');
    voicePartAuditCandidate($fixture, 'Soprano I', 'Young');
    voicePartAuditCandidate($fixture, 'Soprano I', 'Baker');

    $names = app(VoicePartAuditService::class)
        ->rows($fixture['version'])
        ->map(fn (VoicePartAuditRow $row): string => $row->candidate->student->user->last_name)
        ->all();

    expect($names)->toBe(['Baker', 'Young', 'Zimmer', 'Aardvark']);
});

test('the mismatch filter shows only voice parts not offered for the grade, and active is the default status', function () {
    $fixture = voicePartAuditFixture();
    voicePartAuditCandidate($fixture, 'Soprano', 'Legacy');
    voicePartAuditCandidate($fixture, 'Soprano I', 'Correct');
    voicePartAuditCandidate($fixture, 'Soprano', 'Withdrawnson', status: CandidateStatus::TeacherWithdrawn);

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $fixture['version']->id)
        ->set('mismatchOnly', true)
        ->assertSee('Legacy')
        ->assertSee('not offered for')
        ->assertDontSee('Correct')
        ->assertDontSee('Withdrawnson')
        ->set('status', VoicePartAuditService::STATUS_ALL)
        ->assertSee('Withdrawnson');
});

test('changing to an offered voice part updates the candidate', function () {
    $fixture = voicePartAuditFixture();
    $candidate = voicePartAuditCandidate($fixture, 'Soprano', 'Legacy');

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $fixture['version']->id)
        ->call('changeVoicePart', $candidate->id, (string) $fixture['parts']['Soprano I']->id)
        ->assertHasNoErrors();

    expect($candidate->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano I']->id);
});

test('changing to a voice part not offered for the grade is refused', function () {
    $fixture = voicePartAuditFixture();
    $candidate = voicePartAuditCandidate($fixture, 'Soprano I', 'Correct');

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $fixture['version']->id)
        ->call('changeVoicePart', $candidate->id, (string) $fixture['parts']['Soprano']->id)
        ->assertSet('refreshNonce', 1);

    expect($candidate->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano I']->id);
});

test('a scored candidate is refused and nothing is deleted', function () {
    $fixture = voicePartAuditFixture();
    $candidate = voicePartAuditCandidate($fixture, 'Soprano', 'Scored');
    voicePartAuditAddScore($candidate);
    Recording::create([
        'version_id' => $candidate->version_id,
        'candidate_id' => $candidate->id,
        'file_type' => 'scales',
        'uploaded_by' => $fixture['teacher']->user->id,
        'url' => "candidateUploads/{$candidate->id}/scales.mp3",
    ]);

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $fixture['version']->id)
        ->call('changeVoicePart', $candidate->id, (string) $fixture['parts']['Soprano I']->id)
        ->assertSet('refreshNonce', 1);

    expect($candidate->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano']->id)
        ->and(DB::table('scores')->where('candidate_id', $candidate->id)->count())->toBe(1)
        ->and(Recording::where('candidate_id', $candidate->id)->count())->toBe(1);
});

test('a candidate from a different version than the one selected is refused', function () {
    $fixture = voicePartAuditFixture();
    $candidate = voicePartAuditCandidate($fixture, 'Soprano', 'Elsewhere');
    $otherVersion = Version::factory()->create();

    Livewire::actingAs(makeVoicePartAuditFounder())
        ->test(VoicePartAudit::class)
        ->set('versionId', $otherVersion->id)
        ->call('changeVoicePart', $candidate->id, (string) $fixture['parts']['Soprano I']->id)
        ->assertSet('refreshNonce', 1);

    expect($candidate->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano']->id);
});

test('the service throws for a not-offered part and for a scored candidate', function () {
    $fixture = voicePartAuditFixture();
    $service = app(VoicePartAuditService::class);
    $candidate = voicePartAuditCandidate($fixture, 'Soprano', 'Thrower');

    expect(fn () => $service->changeVoicePart($candidate, $fixture['parts']['Soprano']->id, []))
        ->toThrow(VoicePartChangeRefusedException::class, "isn't offered");

    voicePartAuditAddScore($candidate);

    expect(fn () => $service->changeVoicePart($candidate, $fixture['parts']['Soprano I']->id, []))
        ->toThrow(VoicePartChangeRefusedException::class, 'already has scores');
});
