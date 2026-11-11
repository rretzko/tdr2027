<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Ensemble;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

/**
 * A Version whose only Ensemble offers Soprano I/II and Tenor I — so the
 * legacy "Soprano" and "Descant" parts are offered to no grade at all.
 *
 * @return array{version: Version, teacher: Teacher, parts: array<string, VoicePart>}
 */
function voicePartMismatchFixture(): array
{
    $version = Version::factory()->create();
    $parts = [];

    foreach (['Descant' => 1, 'Soprano' => 2, 'Soprano I' => 3, 'Soprano II' => 4, 'Tenor I' => 9] as $name => $order) {
        $parts[$name] = VoicePart::factory()->create(['name' => $name, 'sort_order' => $order]);
    }

    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $ensemble->voiceParts()->attach([$parts['Soprano I']->id, $parts['Soprano II']->id, $parts['Tenor I']->id]);

    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Director'])->id]);
    actingAs($teacher->user);

    return ['version' => $version, 'teacher' => $teacher, 'parts' => $parts];
}

function voicePartMismatchCandidate(array $fixture, string $part, CandidateStatus $status = CandidateStatus::Pending): Candidate
{
    return Candidate::factory()->create([
        'version_id' => $fixture['version']->id,
        'teacher_id' => $fixture['teacher']->id,
        'voice_part_id' => $fixture['parts'][$part]->id,
        'status' => $status,
    ]);
}

test('dry run lists mismatched candidates but writes nothing', function () {
    $fixture = voicePartMismatchFixture();
    $mismatched = voicePartMismatchCandidate($fixture, 'Soprano');
    $fine = voicePartMismatchCandidate($fixture, 'Tenor I');

    artisan('candidates:voice-part-mismatches', ['version' => $fixture['version']->id])
        ->expectsOutputToContain('Would change (1)')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($mismatched->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano']->id)
        ->and($fine->refresh()->voice_part_id)->toBe($fixture['parts']['Tenor I']->id);
});

test('apply maps a base part onto the first offered extension and leaves the rest for review', function () {
    $fixture = voicePartMismatchFixture();
    $soprano = voicePartMismatchCandidate($fixture, 'Soprano');
    $descant = voicePartMismatchCandidate($fixture, 'Descant');
    $adjudicated = voicePartMismatchCandidate($fixture, 'Soprano', CandidateStatus::Accepted);

    artisan('candidates:voice-part-mismatches', ['version' => $fixture['version']->id, '--apply' => true, '--force' => true])
        ->expectsOutputToContain('Needs manual review (2)')
        ->expectsOutputToContain('Changed 1 Candidate(s).')
        ->assertSuccessful();

    expect($soprano->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano I']->id)
        ->and($descant->refresh()->voice_part_id)->toBe($fixture['parts']['Descant']->id)
        ->and($adjudicated->refresh()->voice_part_id)->toBe($fixture['parts']['Soprano']->id);
});

test('csv lists candidate id, student, teacher, voice parts and uploads', function () {
    $fixture = voicePartMismatchFixture();
    $candidate = voicePartMismatchCandidate($fixture, 'Soprano');
    $candidate->student->user->update(['first_name' => 'Samantha', 'last_name' => 'Linnell']);

    $slot = VersionUploadFile::create(['version_id' => $fixture['version']->id, 'name' => 'Scales', 'order_by' => 1]);
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'original_filename' => 'soprano1 scales.mp3',
        'status' => CandidateUploadStatus::Approved->value,
        'uploaded_at' => now(),
    ]);

    expect(Artisan::call('candidates:voice-part-mismatches', ['version' => $fixture['version']->id, '--csv' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('candidate_id,student_last,student_first,grade,teacher_last,teacher_first')
        ->toContain("{$candidate->id},Linnell,Samantha,")
        ->toContain('Director,Pat')
        ->toContain('Soprano,"Soprano I",apply')
        ->toContain('Scales [approved] soprano1 scales.mp3');
});

test('reports a clean version', function () {
    $fixture = voicePartMismatchFixture();
    voicePartMismatchCandidate($fixture, 'Soprano II');

    artisan('candidates:voice-part-mismatches', ['version' => $fixture['version']->id])
        ->expectsOutputToContain('has a voice part offered to their grade')
        ->assertSuccessful();
});
