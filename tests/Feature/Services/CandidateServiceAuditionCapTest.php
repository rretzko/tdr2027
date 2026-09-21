<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('recalculateStatus registers only the first N candidates at a school once the audition cap is reached', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create(['audition_cap_per_school' => 2]);
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $candidates = Candidate::factory()->count(3)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Eligible,
    ]);

    $service = new CandidateService;
    foreach ($candidates as $candidate) {
        $service->recalculateStatus($candidate, []);
    }

    [$first, $second, $third] = $candidates->fresh();

    expect($first->status)->toBe(CandidateStatus::Registered);
    expect($second->status)->toBe(CandidateStatus::Registered);
    expect($third->status)->toBe(CandidateStatus::Pending);
});

test('withdrawing an over-cap slot lets the next candidate register on their next recalculation', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create(['audition_cap_per_school' => 1]);
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $first = Candidate::factory()->create([
        'version_id' => $version->id, 'school_id' => $school->id, 'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Eligible,
    ]);
    $second = Candidate::factory()->create([
        'version_id' => $version->id, 'school_id' => $school->id, 'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Eligible,
    ]);

    $service = new CandidateService;
    $service->recalculateStatus($first, []);
    $service->recalculateStatus($second, []);

    expect($first->fresh()->status)->toBe(CandidateStatus::Registered);
    expect($second->fresh()->status)->toBe(CandidateStatus::Pending);

    $service->withdraw($first->fresh());
    $service->recalculateStatus($second->fresh(), []);

    expect($second->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('recalculateStatus is unaffected when the version has no audition cap configured', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create(['audition_cap_per_school' => null]);
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $candidates = Candidate::factory()->count(5)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Eligible,
    ]);

    $service = new CandidateService;
    foreach ($candidates as $candidate) {
        $service->recalculateStatus($candidate, []);
    }

    expect($candidates->fresh()->every(fn (Candidate $c): bool => $c->getRawOriginal('status') === CandidateStatus::Registered->value))->toBeTrue();
});
