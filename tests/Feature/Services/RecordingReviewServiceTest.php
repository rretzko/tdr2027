<?php

declare(strict_types=1);

use App\Enums\CandidateUploadStatus;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionUploadFile;
use App\Services\RecordingReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * A genuine, valid WAV file of an exact known duration (silence, 8-bit mono
 * PCM @ 8kHz) — UploadedFile::fake()->create() produces garbage bytes with
 * no real embedded duration metadata, so it can't exercise real getID3
 * extraction the way a real audio file does.
 */
function makeDurationTestWavFile(float $durationSeconds, string $filename = 'recording.wav'): UploadedFile
{
    $sampleRate = 8000;
    $numSamples = (int) round($durationSeconds * $sampleRate);

    $header = 'RIFF'
        .pack('V', 36 + $numSamples)
        .'WAVE'
        .'fmt '
        .pack('V', 16)
        .pack('v', 1)
        .pack('v', 1)
        .pack('V', $sampleRate)
        .pack('V', $sampleRate)
        .pack('v', 1)
        .pack('v', 8)
        .'data'
        .pack('V', $numSamples);

    $path = tempnam(sys_get_temp_dir(), 'wav');
    file_put_contents($path, $header.str_repeat("\x80", $numSamples));

    return new UploadedFile($path, $filename, 'audio/wav', null, true);
}

test('extractDurationSeconds reads the real duration from a genuine audio file', function () {
    $service = new RecordingReviewService;
    $file = makeDurationTestWavFile(3.0);

    expect($service->extractDurationSeconds($file->getRealPath()))->toBe(3);
});

test('extractDurationSeconds returns null for an unreadable path', function () {
    $service = new RecordingReviewService;

    expect($service->extractDurationSeconds('/nonexistent/path/file.mp3'))->toBeNull();
});

test('filenameFlagReason is silent when the filename matches the target slot', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    $reason = $service->filenameFlagReason('Alto Scales.mp3', $scales, collect([$scales, $solo]));

    expect($reason)->toBeNull();
});

test('filenameFlagReason is silent when the filename mentions no known slot', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    $reason = $service->filenameFlagReason('New Recording 3.m4a', $solo, collect([$scales, $solo]));

    expect($reason)->toBeNull();
});

test('filenameFlagReason flags when the filename mentions a different known slot', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    $reason = $service->filenameFlagReason('Alto on scales.mp3', $solo, collect([$scales, $solo]));

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Scales');
    expect($reason)->toContain('Solo');
});

test('filenameFlagReason is silent when the filename mentions both the target and another slot', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    $reason = $service->filenameFlagReason('scales and solo combined.mp3', $solo, collect([$scales, $solo]));

    expect($reason)->toBeNull();
});

test('durationFlagReason is silent until the minimum sample size of approved uploads exists', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs(User::factory()->create());
    // Only two approved priors — below MIN_SAMPLE_SIZE (3).
    foreach ([60, 62] as $duration) {
        $candidate = Candidate::factory()->create(['version_id' => $version->id]);
        CandidateUploadFile::create([
            'candidate_id' => $candidate->id,
            'version_upload_file_id' => $slot->id,
            'url' => 'x',
            'duration_seconds' => $duration,
            'status' => CandidateUploadStatus::Approved->value,
            'uploaded_at' => now(),
        ]);
    }

    // Wildly different from the two priors, but there still aren't enough
    // of them for a baseline.
    expect($service->durationFlagReason(300, $slot))->toBeNull();
});

test('durationFlagReason is silent for a duration within the typical range', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs(User::factory()->create());
    foreach ([58, 60, 62, 61, 59] as $duration) {
        $candidate = Candidate::factory()->create(['version_id' => $version->id]);
        CandidateUploadFile::create([
            'candidate_id' => $candidate->id,
            'version_upload_file_id' => $slot->id,
            'url' => 'x',
            'duration_seconds' => $duration,
            'status' => CandidateUploadStatus::Approved->value,
            'uploaded_at' => now(),
        ]);
    }

    expect($service->durationFlagReason(60, $slot))->toBeNull();
});

test('durationFlagReason flags a clear outlier against the approved baseline', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs(User::factory()->create());
    foreach ([58, 60, 62, 61, 59] as $duration) {
        $candidate = Candidate::factory()->create(['version_id' => $version->id]);
        CandidateUploadFile::create([
            'candidate_id' => $candidate->id,
            'version_upload_file_id' => $slot->id,
            'url' => 'x',
            'duration_seconds' => $duration,
            'status' => CandidateUploadStatus::Approved->value,
            'uploaded_at' => now(),
        ]);
    }

    // A 12-second "scales" clip length against a "solo" baseline clustered
    // tightly around ~60 seconds.
    $reason = $service->durationFlagReason(12, $slot);

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Solo');
});

test('durationFlagReason only counts approved uploads toward the baseline, not pending or other slots', function () {
    $service = new RecordingReviewService;
    $version = Version::factory()->create();
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);
    $otherSlot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 2]);

    actingAs(User::factory()->create());
    // Three pending (unapproved) uploads for the same slot — should not
    // count toward the baseline sample size at all.
    foreach ([58, 60, 62] as $duration) {
        $candidate = Candidate::factory()->create(['version_id' => $version->id]);
        CandidateUploadFile::create([
            'candidate_id' => $candidate->id,
            'version_upload_file_id' => $slot->id,
            'url' => 'x',
            'duration_seconds' => $duration,
            'status' => CandidateUploadStatus::Pending->value,
            'uploaded_at' => now(),
        ]);
    }
    // Three approved uploads for a *different* slot — should not count either.
    foreach ([58, 60, 62] as $duration) {
        $candidate = Candidate::factory()->create(['version_id' => $version->id]);
        CandidateUploadFile::create([
            'candidate_id' => $candidate->id,
            'version_upload_file_id' => $otherSlot->id,
            'url' => 'x',
            'duration_seconds' => $duration,
            'status' => CandidateUploadStatus::Approved->value,
            'uploaded_at' => now(),
        ]);
    }

    expect($service->durationFlagReason(12, $slot))->toBeNull();
});
