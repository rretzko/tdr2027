<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\EventStatus;
use App\Livewire\Events\VersionScorecard;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleCutoff;
use App\Models\VersionEnsembleOrder;
use App\Models\VoicePart;
use App\Services\SelectionAnalysisService;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A decided candidate with the audition_results total the decision was made on.
 */
function selectionAnalysisCandidate(Version $version, VoicePart $voicePart, CandidateStatus $status, int $total, ?Ensemble $ensemble = null): Candidate
{
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'status' => $status,
        'accepted_ensemble_id' => $ensemble?->id,
    ]);

    DB::table('audition_results')->insert([
        'candidate_id' => $candidate->id,
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'school_id' => $candidate->school_id,
        'voice_part_order_by' => 1,
        'score_count' => 6,
        'total' => $total,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $candidate;
}

function selectionAnalysisEnsemble(Version $version, VoicePart $voicePart, string $name, int $order): Ensemble
{
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id, 'name' => $name]);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => $order]);

    return $ensemble;
}

test('it returns null until a candidate has been decided', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id]);
    Candidate::factory()->registered()->create(['version_id' => $version->id]);

    expect(app(SelectionAnalysisService::class)->forVersion($version))->toBeNull();
});

test('a shared-cutoff line is the worst accepted total, with margins measured from it', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'score_order' => 'asc', 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    $voicePart = VoicePart::factory()->create(['abbr' => 'S1']);
    $ensemble = selectionAnalysisEnsemble($version, $voicePart, 'Treble', 1);

    foreach ([80, 90, 100] as $total) {
        selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, $total, $ensemble);
    }
    foreach ([101, 103, 120] as $total) {
        selectionAnalysisCandidate($version, $voicePart, CandidateStatus::NotAccepted, $total);
    }

    $analysis = app(SelectionAnalysisService::class)->forVersion($version);
    $line = $analysis['lines'][0] ?? null;

    expect($analysis['perEnsemble'])->toBeFalse()
        ->and($analysis['lines'])->toHaveCount(1)
        ->and($line)->toMatchArray([
            'ensemble' => null,
            'line' => 100,
            'lineSource' => 'lowest_accepted',
            'near' => 3,
            'pool' => 6,
            'accepted' => 3,
            'missed' => 3,
            'acceptRate' => 50,
            'closestMiss' => 1,
            'nearAccepted' => 1,
            'nearMissed' => 2,
            'tiedMissed' => 0,
            'acceptedMedian' => 90.0,
            'missedMedian' => 103.0,
        ])
        ->and($analysis['ensembles'])->toBe([['id' => $ensemble->id, 'name' => 'Treble', 'accepted' => 3, 'priorYear' => $version->senior_class_of - 1, 'prior' => null]]);

    expect($line['acceptedRange'])->toBe([80, 100])
        ->and($line['missedRange'])->toBe([101, 120]);
});

test('per-Ensemble lines use the stored cutoff and leave candidates accepted elsewhere out of the pool', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::SequentialEnsembleFill->value]);
    $voicePart = VoicePart::factory()->create();
    $first = selectionAnalysisEnsemble($version, $voicePart, 'Mixed', 1);
    $second = selectionAnalysisEnsemble($version, $voicePart, 'Treble', 2);
    VersionEnsembleCutoff::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'ensemble_id' => $first->id, 'score' => 90]);
    VersionEnsembleCutoff::create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'ensemble_id' => $second->id, 'score' => 70]);

    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 95, $first);
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 92, $first);
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 85, $second);
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 72, $second);
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::NotAccepted, 75); // meets the second line yet wasn't accepted
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::NotAccepted, 68);

    $lines = collect(app(SelectionAnalysisService::class)->forVersion($version)['lines'] ?? [])->keyBy(fn (array $line): int => $line['ensemble']['id'] ?? 0);

    expect($lines->get($first->id))->toMatchArray(['line' => 90, 'lineSource' => 'set', 'pool' => 4, 'accepted' => 2, 'missed' => 2, 'closestMiss' => 15, 'tiedMissed' => 0])
        ->and($lines->get($second->id))->toMatchArray(['line' => 70, 'lineSource' => 'set', 'pool' => 4, 'accepted' => 2, 'missed' => 2, 'closestMiss' => 2, 'tiedMissed' => 1]);
});

test('outcomes count declines as accepted and tally no-shows', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id]);
    $voicePart = VoicePart::factory()->create();

    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 50);
    selectionAnalysisCandidate($version, $voicePart, CandidateStatus::NotAccepted, 60);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Declined]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::NoShow]);

    expect(app(SelectionAnalysisService::class)->forVersion($version)['outcomes'] ?? null)->toBe([
        'decided' => 3, 'accepted' => 2, 'notAccepted' => 1, 'noShow' => 1, 'incomplete' => 0, 'declined' => 1, 'acceptRate' => 67,
    ]);
});

test('a Version with decisions but no recorded totals gets outcomes and no lines', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);

    $analysis = app(SelectionAnalysisService::class)->forVersion($version);

    expect($analysis['outcomes']['accepted'] ?? null)->toBe(1)
        ->and($analysis['lines'] ?? null)->toBe([]);
});

test('the Scorecard opens on the Selection tab for a closed Version and draws a range bar per line', function () {
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Closed, 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    $manager = User::factory()->create();
    app(VersionRoleService::class)->withVersion($version, fn () => $manager->assignRole('Event Manager'));

    $soprano = VoicePart::factory()->create(['abbr' => 'Sop', 'sort_order' => 1]);
    $alto = VoicePart::factory()->create(['abbr' => 'Alto', 'sort_order' => 2]);
    $ensemble = selectionAnalysisEnsemble($version, $soprano, 'Treble Choir', 1);
    $ensemble->voiceParts()->attach($alto->id);
    selectionAnalysisCandidate($version, $soprano, CandidateStatus::Accepted, 80, $ensemble);
    selectionAnalysisCandidate($version, $alto, CandidateStatus::Accepted, 90, $ensemble);
    selectionAnalysisCandidate($version, $alto, CandidateStatus::NotAccepted, 95);

    Livewire::actingAs($manager)
        ->test(VersionScorecard::class, ['version' => $version])
        ->assertSet('tab', 'selection')
        ->assertSee('How close the cut-offs were')
        ->assertSee('Treble Choir accepted candidates by voice part')
        ->assertSee('Sop: cut-off 80; accepted 1 from 80 to 80; none not accepted')
        ->assertSee('Alto: cut-off 90; accepted 1 from 90 to 90; not accepted 1 from 95 to 95');
});

test('lines are grouped by Ensemble order, then Voice Part order', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::SequentialEnsembleFill->value]);
    $low = VoicePart::factory()->create(['abbr' => 'Low', 'sort_order' => 1]);
    $high = VoicePart::factory()->create(['abbr' => 'High', 'sort_order' => 2]);
    $first = selectionAnalysisEnsemble($version, $low, 'First', 1);
    $first->voiceParts()->attach($high->id);
    $second = selectionAnalysisEnsemble($version, $low, 'Second', 2);
    $second->voiceParts()->attach($high->id);

    foreach ([$low, $high] as $voicePart) {
        selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 90, $first);
        selectionAnalysisCandidate($version, $voicePart, CandidateStatus::Accepted, 80, $second);
    }

    $order = collect(app(SelectionAnalysisService::class)->forVersion($version)['lines'] ?? [])
        ->map(fn (array $line): string => $line['ensemble']['name'].' '.$line['voicePart']['abbr'])
        ->all();

    expect($order)->toBe(['First Low', 'First High', 'Second Low', 'Second High']);
});
