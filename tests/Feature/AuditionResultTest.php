<?php

declare(strict_types=1);

use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('audition result is mass-assignable and resolves every belongsTo relation', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    $result = AuditionResult::create([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $candidate->voice_part_id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 3,
        'score_count' => 18,
        'total' => 71,
    ]);

    expect($result->candidate->id)->toBe($candidate->id);
    expect($result->version->id)->toBe($version->id);
    expect($result->voicePart->id)->toBe($candidate->voice_part_id);
    expect($result->school->id)->toBe($candidate->school_id);
    expect($result->score_count)->toBe(18);
    expect($result->total)->toBe(71);
});

test('a candidate can have at most one audition result', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    AuditionResult::create([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $candidate->voice_part_id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 1,
        'score_count' => 18,
        'total' => 71,
    ]);

    expect(fn () => AuditionResult::create([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $candidate->voice_part_id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 1,
        'score_count' => 18,
        'total' => 90,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('deleting a candidate cascades to delete its audition result', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    $result = AuditionResult::create([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $candidate->voice_part_id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 1,
        'score_count' => 18,
        'total' => 71,
    ]);

    $candidate->delete();

    expect(AuditionResult::find($result->id))->toBeNull();
});
