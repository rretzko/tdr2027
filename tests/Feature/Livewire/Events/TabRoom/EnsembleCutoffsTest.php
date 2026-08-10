<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Livewire\Events\TabRoom\EnsembleCutoffs;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\EnsembleHistory;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * A Tab Room Manager, an active Version with a cutoff_strategy configured,
 * one Ensemble/VoicePart/Room/factor, and two registered Candidates ready
 * to be ranked.
 *
 * @return array{manager: User, version: Version, voicePart: VoicePart, ensemble: Ensemble, factor: ScoreFactor, judge: RoomJudge, high: Candidate, low: Candidate}
 */
function makeEnsembleCutoffScenario(CutoffStrategy $strategy): array
{
    $manager = User::factory()->create();
    actingAs($manager); // CandidateObserver logs status history against the acting user.
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => $strategy->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $voicePart = VoicePart::factory()->create();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'Tone',
        'abbreviation' => 'TN',
        'best' => 100,
        'worst' => 0,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $high = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $low = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $high, $version, [$factor->id => 90]);
    app(AdjudicationService::class)->saveScores($judge, $low, $version, [$factor->id => 40]);

    return compact('manager', 'version', 'voicePart', 'ensemble', 'factor', 'judge', 'high', 'low');
}

test('mount succeeds for a Tab Room Manager', function () {
    ['manager' => $manager, 'version' => $version] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 422 when the Version has no cutoff_strategy configured', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->assertStatus(422);
});

test('applyCutoff resolves a single-cutoff-strategy Voice Part in one call', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high, 'low' => $low] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $voicePart->id, 70);

    expect($high->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($high->fresh()->accepted_ensemble_id)->toBe($ensemble->id);
    expect($low->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('reopenVoicePart only toggles the view — the roster and its current decisions stay intact', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high, 'low' => $low] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $voicePart->id, 70)
        ->call('reopenVoicePart', $voicePart->id)
        ->assertDontSee('Resolved')
        ->assertSee('90')
        ->assertSee('40');

    // Reopening is display-only — the existing decision is untouched.
    expect($high->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($high->fresh()->accepted_ensemble_id)->toBe($ensemble->id);
    expect($low->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('clicking a new score after reopening re-decides the Voice Part in place', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high, 'low' => $low] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $voicePart->id, 70)
        ->call('reopenVoicePart', $voicePart->id)
        ->call('applyCutoff', $voicePart->id, 95); // raise the bar above $high's own score too

    expect($high->fresh()->status)->toBe(CandidateStatus::NotAccepted);
    expect($high->fresh()->accepted_ensemble_id)->toBeNull();
    expect($low->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('after a cutoff resolves a Voice Part, the page shows the resolved outcome instead of the misleading empty-ranked-list message', function () {
    // Regression: rankedCandidates() only shows candidates still awaiting
    // a decision, so once applyCutoff() resolves everyone it legitimately
    // returns empty — the page must distinguish "already resolved" from
    // "nobody's finished scoring yet" rather than showing the latter
    // message in both cases.
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $voicePart->id, 70)
        ->assertDontSee('No fully-scored candidates yet')
        ->assertSee('Resolved')
        ->assertSee('1 in')
        ->assertSee('1 out');
});

test('Ensemble Breakdown pie chart shows one slice per Voice Part with correct totals, percents, and direct abbr labels', function () {
    // One Ensemble spanning two Voice Parts (like Mixed Chorus spanning
    // Soprano and Tenor), each with its own Room/rubric, one accepted
    // candidate apiece — the pie for this Ensemble should show two
    // 50%/50% slices, not just the single-slice 100% edge case.
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);

    $sopranoI = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI', 'sort_order' => 1]);
    $tenorI = VoicePart::factory()->create(['name' => 'Tenor I', 'abbr' => 'TI', 'sort_order' => 5]);
    $ensemble->voiceParts()->attach([$sopranoI->id, $tenorI->id]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);

    $sopranoRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano Room', 'order_by' => 1]);
    $sopranoRoom->voiceParts()->attach($sopranoI->id);
    $sopranoRoom->scoreCategories()->attach($category->id);
    $sopranoJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $sopranoRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    $tenorRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Tenor Room', 'order_by' => 2]);
    $tenorRoom->voiceParts()->attach($tenorI->id);
    $tenorRoom->scoreCategories()->attach($category->id);
    $tenorJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $tenorRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    $sopranoCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $sopranoI->id]);
    $tenorCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $tenorI->id]);
    app(AdjudicationService::class)->saveScores($sopranoJudge, $sopranoCandidate, $version, [$factor->id => 90]);
    app(AdjudicationService::class)->saveScores($tenorJudge, $tenorCandidate, $version, [$factor->id => 90]);

    $component = Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $sopranoI->id, 70)
        ->call('applyCutoff', $tenorI->id, 70)
        ->assertSee('Ensemble Breakdown')
        ->assertSee('Mixed Chorus')
        ->assertSee('2 accepted')
        ->assertSee('Soprano I') // full name, in the title/aria-label
        ->assertSee('Tenor I');

    $html = $component->html();

    // Two distinct 50% slices, not one degenerate 100% slice — each
    // slice carries the percent twice (aria-label + <title>).
    expect(substr_count($html, '(50.0%)'))->toBe(4);

    // Both slices are large enough (50% >= the 8% label threshold) to get
    // a direct abbr label on the slice itself.
    expect($html)->toContain('>SI</text>');
    expect($html)->toContain('>TI</text>');
});

test('a slice smaller than the label threshold is not direct-labeled, but stays reachable via hover/focus', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);

    $sopranoI = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI', 'sort_order' => 1]);
    $tenorI = VoicePart::factory()->create(['name' => 'Tenor I', 'abbr' => 'TI', 'sort_order' => 5]);
    $ensemble->voiceParts()->attach([$sopranoI->id, $tenorI->id]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);

    $sopranoRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano Room', 'order_by' => 1]);
    $sopranoRoom->voiceParts()->attach($sopranoI->id);
    $sopranoRoom->scoreCategories()->attach($category->id);
    $sopranoJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $sopranoRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    $tenorRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Tenor Room', 'order_by' => 2]);
    $tenorRoom->voiceParts()->attach($tenorI->id);
    $tenorRoom->scoreCategories()->attach($category->id);
    $tenorJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $tenorRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    // 19 Sopranos (95%) vs 1 Tenor (5%) — the Tenor slice falls below the 8% label threshold.
    $sopranos = Candidate::factory()->registered()->count(19)->create(['version_id' => $version->id, 'voice_part_id' => $sopranoI->id]);
    foreach ($sopranos as $candidate) {
        app(AdjudicationService::class)->saveScores($sopranoJudge, $candidate, $version, [$factor->id => 90]);
    }
    $tenorCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $tenorI->id]);
    app(AdjudicationService::class)->saveScores($tenorJudge, $tenorCandidate, $version, [$factor->id => 90]);

    $html = Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $sopranoI->id, 70)
        ->call('applyCutoff', $tenorI->id, 70)
        ->html();

    expect($html)->toContain('>SI</text>'); // 95% — labeled
    expect($html)->not->toContain('>TI</text>'); // 5% — below threshold, not labeled
    expect($html)->toContain('Tenor I: 1 (5.0%)'); // still reachable via title/aria-label/hover
});

test('applyEnsembleCutoff and finalizeVoicePart resolve a per-Ensemble-strategy Voice Part', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high, 'low' => $low] = makeEnsembleCutoffScenario(CutoffStrategy::SequentialEnsembleFill);

    $key = "{$voicePart->id}_{$ensemble->id}";

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->set("ensembleCutoffInputs.{$key}", '70')
        ->call('applyEnsembleCutoff', $voicePart->id, $ensemble->id)
        ->assertHasNoErrors();

    expect($high->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($low->fresh()->status)->toBe(CandidateStatus::Registered); // not yet finalized

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('finalizeVoicePart', $voicePart->id);

    expect($low->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('the reopened ranked list colors each accepted row with its own Ensemble\'s color, not a generic accepted color', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $voicePart = VoicePart::factory()->create();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $treble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble']);
    $treble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $treble->id, 'order_by' => 1]);

    $mixed = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed']);
    $mixed->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $mixed->id, 'order_by' => 2]);

    $rank0 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $rank1 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $rank0, $version, [$factor->id => 90]);
    app(AdjudicationService::class)->saveScores($judge, $rank1, $version, [$factor->id => 80]);

    $component = Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyCutoff', $voicePart->id, 70)
        ->call('reopenVoicePart', $voicePart->id);

    expect($rank0->fresh()->accepted_ensemble_id)->toBe($treble->id);
    expect($rank1->fresh()->accepted_ensemble_id)->toBe($mixed->id);

    $html = $component->html();
    // Distinct colors for the two Ensembles' palette slots (index 0 and 1).
    expect($html)->toContain('#3b82f6'); // Treble — first palette color
    expect($html)->toContain('#f97316'); // Mixed — second palette color
    expect(substr_count($html, '#3b82f6'))->toBeGreaterThan(1); // appears in both the summary dot and the accepted row
});

test('applyEnsembleCutoff rejects a non-numeric score without applying anything', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high] = makeEnsembleCutoffScenario(CutoffStrategy::SequentialEnsembleFill);

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->call('applyEnsembleCutoff', $voicePart->id, $ensemble->id);

    expect($high->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('saveHistoryRow persists the entered counts and mount pre-hydrates them from EnsembleHistory on the next load', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);
    $priorYear = $version->senior_class_of - 1;
    $key = "{$ensemble->id}_{$priorYear}_{$voicePart->id}";

    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->set("historyInputs.{$key}", '27')
        ->call('saveHistoryRow', $ensemble->id, $priorYear)
        ->assertHasNoErrors();

    expect(EnsembleHistory::where('ensemble_id', $ensemble->id)->where('voice_part_id', $voicePart->id)->where('season_year', $priorYear)->value('accepted_count'))->toBe(27);

    // A fresh mount of the same page pre-fills the input from the saved row.
    Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->assertSet("historyInputs.{$key}", '27');
});

test('the History tables render a live client-side Total column, entangled with historyInputs', function () {
    ['manager' => $manager, 'version' => $version] = makeEnsembleCutoffScenario(CutoffStrategy::PerVoicePartPerEnsemble);

    $html = Livewire::actingAs($manager)
        ->test(EnsembleCutoffs::class, ['version' => $version])
        ->html();

    expect(substr_count($html, '>Total<'))->toBeGreaterThanOrEqual(2); // one per prior season year's table
    expect($html)->toContain('x-data="{ historyInputs:'); // entangle wiring present
    expect($html)->toContain('.reduce((sum, k) => sum + (Number(historyInputs[k]) || 0), 0)');
});

// Snapshotting this season into EnsembleHistory happens on Close (VersionEdit's
// general Status field), not from a button on this page — see VersionEditTest.php's
// "saveGeneral snapshots..." test.
