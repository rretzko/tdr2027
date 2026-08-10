<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\EnsembleHistory;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use App\Services\EnsembleHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => actingAs(User::factory()->create()));

test('priorSeasonYears returns the two years immediately before senior_class_of by default', function () {
    $history = app(EnsembleHistoryService::class);
    $version = Version::factory()->create(['senior_class_of' => 2026]);

    expect($history->priorSeasonYears($version))->toBe([2025, 2024]);
    expect($history->priorSeasonYears($version, 3))->toBe([2025, 2024, 2023]);
});

test('historyGrid nests recorded counts by ensemble, season, and voice part, ignoring seasons/ensembles not asked for', function () {
    $history = app(EnsembleHistoryService::class);
    $event = Event::factory()->create();
    $ensembleA = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensembleB = Ensemble::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create();

    EnsembleHistory::create(['ensemble_id' => $ensembleA->id, 'voice_part_id' => $voicePart->id, 'season_year' => 2025, 'accepted_count' => 40]);
    EnsembleHistory::create(['ensemble_id' => $ensembleA->id, 'voice_part_id' => $voicePart->id, 'season_year' => 2020, 'accepted_count' => 999]); // out of range — ignored
    EnsembleHistory::create(['ensemble_id' => $ensembleB->id, 'voice_part_id' => $voicePart->id, 'season_year' => 2024, 'accepted_count' => 12]);

    $grid = $history->historyGrid(collect([$ensembleA, $ensembleB]), [2025, 2024]);

    expect($grid[$ensembleA->id][2025][$voicePart->id])->toBe(40);
    expect($grid[$ensembleA->id])->not->toHaveKey(2020);
    expect($grid[$ensembleB->id][2024][$voicePart->id])->toBe(12);
});

test('saveHistoryRow upserts one row per Voice Part and deletes rows for null/empty counts', function () {
    $history = app(EnsembleHistoryService::class);
    $event = Event::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $voicePartA = VoicePart::factory()->create();
    $voicePartB = VoicePart::factory()->create();

    $history->saveHistoryRow($ensemble, 2025, [$voicePartA->id => '30', $voicePartB->id => '15']);

    expect(EnsembleHistory::where('ensemble_id', $ensemble->id)->where('season_year', 2025)->count())->toBe(2);
    expect(EnsembleHistory::where('voice_part_id', $voicePartA->id)->value('accepted_count'))->toBe(30);

    // Re-saving with an updated value upserts (no duplicate row), and an
    // empty string clears that Voice Part's row entirely.
    $history->saveHistoryRow($ensemble, 2025, [$voicePartA->id => '35', $voicePartB->id => '']);

    expect(EnsembleHistory::where('ensemble_id', $ensemble->id)->where('season_year', 2025)->count())->toBe(1);
    expect(EnsembleHistory::where('voice_part_id', $voicePartA->id)->value('accepted_count'))->toBe(35);
    expect(EnsembleHistory::where('voice_part_id', $voicePartB->id)->exists())->toBeFalse();
});

test('recordCurrentSeason snapshots EnsembleCutoffService::acceptedCounts() into EnsembleHistory keyed by senior_class_of', function () {
    $history = app(EnsembleHistoryService::class);
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2026]);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create();

    Candidate::factory()->count(3)->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);

    $history->recordCurrentSeason($version, $cutoffs);

    $row = EnsembleHistory::where('ensemble_id', $ensemble->id)->where('voice_part_id', $voicePart->id)->where('season_year', 2026)->first();
    expect($row)->not->toBeNull();
    expect($row->accepted_count)->toBe(3);

    // Re-running (e.g. after a late correction) updates in place, not a duplicate row.
    Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    $history->recordCurrentSeason($version, $cutoffs);

    expect(EnsembleHistory::where('ensemble_id', $ensemble->id)->where('voice_part_id', $voicePart->id)->where('season_year', 2026)->count())->toBe(1);
    expect(EnsembleHistory::where('ensemble_id', $ensemble->id)->where('voice_part_id', $voicePart->id)->where('season_year', 2026)->value('accepted_count'))->toBe(4);
});
