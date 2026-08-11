<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * A Version with one Voice Part/Ensemble/Room/factor, two scored
 * Candidates, and a cutoff already applied (high accepted, low not
 * accepted) — the shared fixture every TabRoomReportService method reads.
 *
 * @return array{version: Version, voicePart: VoicePart, ensemble: Ensemble, high: Candidate, low: Candidate, judge: RoomJudge, factor: ScoreFactor}
 */
function makeTabRoomReportScenario(): array
{
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);

    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI']);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus', 'abbreviation' => 'MC']);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id,
        'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1,
    ]);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $high = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $low = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $high, $version, [$factor->id => 90]);
    app(AdjudicationService::class)->saveScores($judge, $low, $version, [$factor->id => 40]);

    app(EnsembleCutoffService::class)->applyCutoff($version, $voicePart, 70);

    return compact('version', 'voicePart', 'ensemble', 'high', 'low', 'judge', 'factor');
}

test('auditionScoreRows returns one column per judge/factor pair and sorts best-to-worst', function () {
    ['version' => $version, 'voicePart' => $voicePart, 'high' => $high, 'low' => $low, 'judge' => $judge, 'factor' => $factor] = makeTabRoomReportScenario();

    $data = app(TabRoomReportService::class)->auditionScoreRows($version, $voicePart, app(EnsembleCutoffService::class));

    expect($data['columns'])->toHaveCount(1);
    expect($data['columns']->first()['judge_id'])->toBe($judge->id);
    expect($data['columns']->first()['score_factor_id'])->toBe($factor->id);
    // Header is the factor abbreviation alone — no judge name prefix, judges stay anonymous in reports.
    expect($data['columns']->first()['label'])->toBe($factor->abbreviation);

    $rows = $data['rows']->values();
    expect($rows[0]['candidate']->id)->toBe($high->id);
    expect($rows[0]['total'])->toBe(90);
    // Accepted shows the Ensemble's own abbreviation, not the generic status label.
    expect($rows[0]['result'])->toBe('MC');
    expect($rows[1]['candidate']->id)->toBe($low->id);
    expect($rows[1]['result'])->toBe('na');
});

test('resultLabel/resultRank cover accepted-Ensemble-abbreviation, na, inc, ns, and err, sorted in that block order', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc']);
    $voicePart = VoicePart::factory()->create();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'abbreviation' => 'MC']);

    $accepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    $notAccepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NotAccepted]);
    $incomplete = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Incomplete]);
    $noShow = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NoShow]);
    $stillRegistered = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    // Give every Candidate a real score so totals are non-zero and the sort has something to tie-break within its own block.
    foreach ([$accepted, $notAccepted, $incomplete, $noShow, $stillRegistered] as $candidate) {
        app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factor->id => 50]);
    }

    $data = app(TabRoomReportService::class)->auditionScoreRows($version, $voicePart, app(EnsembleCutoffService::class));
    $resultsById = $data['rows']->keyBy(fn (array $row) => $row['candidate']->id)->map(fn (array $row) => $row['result']);

    expect($resultsById[$accepted->id])->toBe('MC');
    expect($resultsById[$notAccepted->id])->toBe('na');
    expect($resultsById[$incomplete->id])->toBe('inc');
    expect($resultsById[$noShow->id])->toBe('ns');
    expect($resultsById[$stillRegistered->id])->toBe('err');

    // Block order: complete auditions (Accepted/Not Accepted) first, then Incomplete, then No Show, then Error.
    $orderedIds = $data['rows']->pluck('candidate.id')->all();
    $blockOf = fn (int $id): int => match ($id) {
        $accepted->id, $notAccepted->id => 0,
        $incomplete->id => 1,
        $noShow->id => 2,
        $stillRegistered->id => 3,
        default => -1,
    };
    expect(array_map($blockOf, $orderedIds))->toBe([0, 0, 1, 2, 3]);
});

test('auditionScoreRows sorts columns by Score Category, then judge_type, then factor abbreviation, and groups them into contiguous categoryGroups', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc']);
    $voicePart = VoicePart::factory()->create();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);

    // Two factors in "Scales", named so alphabetical-by-abbreviation
    // (RH before TN) differs from their order_by (Tone=1, Rhythm=2) — the
    // only way to prove the sort key really is the abbreviation string.
    $scales = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $toneFactor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $scales->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $rhythmFactor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $scales->id, 'description' => 'Rhythm', 'abbreviation' => 'RH', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 2]);
    $room->scoreCategories()->attach($scales->id);

    $solo = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $expressionFactor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $solo->id, 'description' => 'Expression', 'abbreviation' => 'EX', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($solo->id);

    // Two judges of different types scoring the same "Scales" factors —
    // judge_type must sort ahead of factor abbreviation within a category,
    // so Judge 2 (Judge1)'s columns land after Judge 1 (HeadJudge)'s, not
    // interleaved with them.
    $headJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judge1 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge1]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($headJudge, $candidate, $version, [$toneFactor->id => 90, $rhythmFactor->id => 80]);
    app(AdjudicationService::class)->saveScores($judge1, $candidate, $version, [$toneFactor->id => 85, $rhythmFactor->id => 75]);
    app(AdjudicationService::class)->saveScores($headJudge, $candidate, $version, [$expressionFactor->id => 70]);

    $data = app(TabRoomReportService::class)->auditionScoreRows($version, $voicePart, app(EnsembleCutoffService::class));

    expect($data['columns'])->toHaveCount(5);
    // Scales/HeadJudge (RH, TN alphabetically) → Scales/Judge1 (RH, TN) → Solo/HeadJudge (EX).
    expect($data['columns']->pluck('label')->all())->toBe(['RH', 'TN', 'RH', 'TN', 'EX']);
    expect($data['columns']->pluck('judge_id')->all())->toBe([$headJudge->id, $headJudge->id, $judge1->id, $judge1->id, $headJudge->id]);

    expect($data['categoryGroups'])->toHaveCount(2);
    // Every category is boxed now (for consistency with the always-on judge
    // box); only every *even*-numbered category (Solo, index 1) is shaded.
    expect($data['categoryGroups'][0])->toBe(['label' => 'Scales', 'span' => 4, 'box' => true, 'shaded' => false]);
    expect($data['categoryGroups'][1])->toBe(['label' => 'Solo', 'span' => 1, 'box' => true, 'shaded' => true]);

    // Column-level box/edge flags: the box spans the whole Scales run (index
    // 0-3), starting at column 0 and ending at column 3, regardless of the
    // judge boundary in between; Solo (column 4) is its own boxed, shaded,
    // self-contained start-and-end.
    expect($data['columns']->pluck('category_box')->all())->toBe([true, true, true, true, true]);
    expect($data['columns']->pluck('category_shaded')->all())->toBe([false, false, false, false, true]);
    expect($data['columns']->pluck('is_category_start')->all())->toBe([true, false, false, false, true]);
    expect($data['columns']->pluck('is_category_end')->all())->toBe([false, false, false, true, true]);

    // Judge-level box/edge flags (the finer, always-on nested layer) — each
    // of the three judge runs (Head Judge/Scales, Judge 1/Scales, Head
    // Judge/Solo) opens and closes its own box independently of the
    // category boundary.
    expect($data['columns']->pluck('is_judge_start')->all())->toBe([true, false, true, false, true]);
    expect($data['columns']->pluck('is_judge_end')->all())->toBe([false, true, false, true, true]);

    // Head Judge recurs in two non-adjacent category blocks (Scales and
    // Solo) — a naive groupBy('judge_id') would wrongly merge those into
    // one span-3 group; the real output must keep them as three separate
    // runs (Scales/Head Judge, Scales/Judge 1, Solo/Head Judge).
    expect($data['judgeGroups'])->toHaveCount(3);
    // Head Judge/Scales opens the category box but doesn't close it (Judge
    // 1/Scales still follows within the same category); Judge 1/Scales
    // closes it; Head Judge/Solo (shaded) both opens and closes its own
    // category.
    expect($data['judgeGroups'][0])->toBe(['label' => 'Head Judge', 'span' => 2, 'box' => true, 'shaded' => false, 'is_category_start' => true, 'is_category_end' => false]);
    expect($data['judgeGroups'][1])->toBe(['label' => 'Judge 1', 'span' => 2, 'box' => true, 'shaded' => false, 'is_category_start' => false, 'is_category_end' => true]);
    expect($data['judgeGroups'][2])->toBe(['label' => 'Head Judge', 'span' => 1, 'box' => true, 'shaded' => true, 'is_category_start' => true, 'is_category_end' => true]);
});

test('combinedScoreRows returns one entry per Voice Part in the Ensemble, with identity fields loaded', function () {
    ['version' => $version, 'voicePart' => $voicePart, 'ensemble' => $ensemble, 'high' => $high] = makeTabRoomReportScenario();

    $tables = app(TabRoomReportService::class)->combinedScoreRows($version, $ensemble, app(EnsembleCutoffService::class));

    expect($tables)->toHaveCount(1);
    expect($tables->first()['voicePart']->id)->toBe($voicePart->id);

    $highRow = $tables->first()['rows']->firstWhere(fn (array $row): bool => $row['candidate']->id === $high->id);
    expect($highRow['candidate']->relationLoaded('student'))->toBeTrue();
    expect($highRow['candidate']->relationLoaded('school'))->toBeTrue();
    expect($highRow['candidate']->relationLoaded('teacher'))->toBeTrue();
});

test('ensembleParticipationRows only includes accepted Candidates, with their total', function () {
    ['version' => $version, 'ensemble' => $ensemble, 'high' => $high, 'low' => $low] = makeTabRoomReportScenario();

    $rows = app(TabRoomReportService::class)->ensembleParticipationRows($version, $ensemble, app(EnsembleCutoffService::class));

    expect($rows)->toHaveCount(1);
    expect($rows->first()['candidate']->id)->toBe($high->id);
    expect($rows->first()['total'])->toBe(90);
    expect($rows->pluck('candidate.id'))->not->toContain($low->id);
});

test('studentSeniorityRows grids accepted presence across sibling Versions of the same Event', function () {
    ['version' => $version, 'ensemble' => $ensemble, 'high' => $high] = makeTabRoomReportScenario();

    $priorVersion = Version::factory()->create(['event_id' => $version->event_id, 'senior_class_of' => $version->senior_class_of - 1]);
    // The same student did NOT participate the prior year — a sibling Candidate row with a different status.
    Candidate::factory()->create(['version_id' => $priorVersion->id, 'student_id' => $high->student_id, 'status' => CandidateStatus::Withdrew]);

    $rows = app(TabRoomReportService::class)->studentSeniorityRows($version, $ensemble);

    expect($rows)->toHaveCount(1);
    $years = $rows->first()['years'];
    expect($years[(int) $version->senior_class_of])->toBeTrue();
    expect($years[(int) $priorVersion->senior_class_of])->toBeFalse();
});

test('auditionScoreRows is cached until TabRoomReportCache::forget() runs', function () {
    ['version' => $version, 'voicePart' => $voicePart, 'high' => $high] = makeTabRoomReportScenario();
    $reports = app(TabRoomReportService::class);
    $cutoffs = app(EnsembleCutoffService::class);

    $first = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($first['rows']->firstWhere('candidate.id', $high->id)['total'])->toBe(90);

    // Mutate the underlying Score directly, bypassing AdjudicationService::
    // saveScores() (which itself calls forget()) — proves the next read is
    // really served from cache, not recomputed, until forget() runs.
    Score::where('candidate_id', $high->id)->update(['score' => 999]);

    $stillCached = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($stillCached['rows']->firstWhere('candidate.id', $high->id)['total'])->toBe(90);

    TabRoomReportCache::forget($version);

    $fresh = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($fresh['rows']->firstWhere('candidate.id', $high->id)['total'])->toBe(999);
});

test('AdjudicationService::saveScores() invalidates the Tab Room report cache automatically', function () {
    ['version' => $version, 'voicePart' => $voicePart, 'high' => $high, 'judge' => $judge, 'factor' => $factor] = makeTabRoomReportScenario();
    $reports = app(TabRoomReportService::class);
    $cutoffs = app(EnsembleCutoffService::class);

    $first = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($first['rows']->firstWhere('candidate.id', $high->id)['total'])->toBe(90);

    app(AdjudicationService::class)->saveScores($judge, $high, $version, [$factor->id => 55]);

    $updated = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($updated['rows']->firstWhere('candidate.id', $high->id)['total'])->toBe(55);
});

test('EnsembleCutoffService::applyCutoff() invalidates the Tab Room report cache automatically', function () {
    ['version' => $version, 'voicePart' => $voicePart, 'high' => $high, 'low' => $low] = makeTabRoomReportScenario();
    $reports = app(TabRoomReportService::class);
    $cutoffs = app(EnsembleCutoffService::class);

    $first = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($first['rows']->firstWhere('candidate.id', $low->id)['result'])->toBe('na');

    // Reopen and raise the bar above $high's own score too — both should
    // now read as rejected, which only shows up if the cache was actually
    // invalidated by this call.
    $cutoffs->applyCutoff($version, $voicePart, 95);

    $updated = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    expect($updated['rows']->firstWhere('candidate.id', $high->id)['result'])->toBe('na');
});

test('allEnsemblesScoreRows survives a real serializing cache store when two Ensembles share a Voice Part (regression for the 2026-08-11 production crash)', function () {
    // The test suite's default CACHE_STORE ('array') never round-trips
    // through serialize()/unserialize(), so it can't catch this bug class —
    // forcing 'database' here exercises the real unserialize($v,
    // ['allowed_classes' => false]) path config/cache.php sets app-wide.
    config(['cache.default' => 'database']);

    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);

    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI']);
    $firstEnsemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble Choir', 'abbreviation' => 'TC']);
    $secondEnsemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus', 'abbreviation' => 'MC']);

    // Both Ensembles share this Voice Part — realistic context for the
    // regression, even though what's actually exercised below is calling
    // auditionScoreRows() for that shared Voice Part twice directly.
    // allEnsemblesScoreRows() itself now de-duplicates shared Voice Parts
    // (product decision 2026-08-11 — "All Ensembles" has no meaningful
    // per-Ensemble grouping, see its docblock), so it no longer naturally
    // re-touches the same cache key twice in one request the way it used
    // to; combinedScoreRows() is accepted-only and this fixture applies no
    // cutoff. Calling auditionScoreRows() twice directly still hits the
    // same underlying audition_score_rows:{voicePart->id} cache key on the
    // second call — a cache HIT, must unserialize it back — exactly the
    // sequence that crashed in production.
    $firstEnsemble->voiceParts()->attach($voicePart->id);
    $secondEnsemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $firstEnsemble->id, 'order_by' => 1]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $secondEnsemble->id, 'order_by' => 2]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id,
        'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1,
    ]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factor->id => 90]);

    $reports = app(TabRoomReportService::class);
    $cutoffs = app(EnsembleCutoffService::class);

    $first = $reports->auditionScoreRows($version, $voicePart, $cutoffs);
    $second = $reports->auditionScoreRows($version, $voicePart, $cutoffs);

    foreach ([$first, $second] as $data) {
        $row = $data['rows']->first();
        expect($row['candidate'])->toBeInstanceOf(Candidate::class);
        expect($row['candidate']->id)->toBe($candidate->id);
        expect($row['total'])->toBe(90);
    }
});

test('allEnsemblesScoreRows renders a Voice Part shared by multiple Ensembles exactly once', function () {
    ['version' => $version, 'voicePart' => $voicePart] = makeTabRoomReportScenario();
    $reports = app(TabRoomReportService::class);
    $cutoffs = app(EnsembleCutoffService::class);

    $secondEnsemble = Ensemble::factory()->create(['event_id' => $version->event_id, 'name' => 'Treble Chorus', 'abbreviation' => 'TC']);
    $secondEnsemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $secondEnsemble->id, 'order_by' => 2]);

    $data = $reports->allEnsemblesScoreRows($version, $cutoffs);

    expect($data)->toHaveCount(1);
    expect($data->first()['voicePart']->id)->toBe($voicePart->id);
});
