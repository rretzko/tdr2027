<?php

declare(strict_types=1);

use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// CandidateObserver logs status-change history against the acting user —
// every Candidate::factory() call needs one, same as tests\Feature\ScoreTest.php.
beforeEach(fn () => actingAs(User::factory()->create()));

function makeAdjudicationRoom(Version $version, ?int $tolerance = null): VersionRoom
{
    return VersionRoom::create([
        'version_id' => $version->id,
        'name' => 'Test Room',
        'tolerance' => $tolerance,
        'order_by' => 1,
    ]);
}

function makeScoreFactor(Version $version, ScoreCategory $category, array $overrides = []): ScoreFactor
{
    return ScoreFactor::create(array_merge([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'Tone',
        'abbreviation' => 'TN',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ], $overrides));
}

test('candidatesForRoom orders candidates by voice part sort_order then candidate id, spanning multiple voice parts', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);

    $altoPart = VoicePart::factory()->create(['sort_order' => 2]);
    $sopranoPart = VoicePart::factory()->create(['sort_order' => 1]);
    $room->voiceParts()->attach([$altoPart->id, $sopranoPart->id]);

    $alto = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $altoPart->id]);
    $sopranoA = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $sopranoPart->id]);
    $sopranoB = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $sopranoPart->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $sopranoPart->id]); // not Registered — excluded
    Candidate::factory()->registered()->create(['voice_part_id' => $sopranoPart->id]); // different version — excluded

    $ordered = $service->candidatesForRoom($room)->pluck('id')->all();
    $expectedSopranoPair = collect([$sopranoA->id, $sopranoB->id])->sort()->values()->all();

    expect($ordered)->toHaveCount(3);
    expect(array_slice($ordered, 0, 2))->toEqualCanonicalizing($expectedSopranoPair);
    expect($ordered[2])->toBe($alto->id);
});

test('candidateStatuses buckets none, partial, completed, and error correctly', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factorA = makeScoreFactor($version, $category, ['description' => 'A', 'abbreviation' => 'A']);
    $factorB = makeScoreFactor($version, $category, ['description' => 'B', 'abbreviation' => 'B', 'order_by' => 2]);
    $room->scoreCategories()->attach($category->id);

    // A factor under a category NOT attached to this room — used only to
    // simulate a stale/extra score row inflating the count past max (e.g.
    // after a rubric change), the "error" bucket's real-world cause. Not
    // introduced via a 3rd RoomJudge, since that would raise judgeCount
    // itself and change max for every candidate in this room, not just this
    // one; and not the same category as factorA/factorB, since roomRubric()
    // pulls every factor under an attached category (category-level scoping,
    // not per-factor).
    $strayCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Unrelated', 'order_by' => 2]);
    $strayFactor = makeScoreFactor($version, $strayCategory, ['description' => 'Stray', 'abbreviation' => 'ST', 'order_by' => 1]);

    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

    $none = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $partial = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $completed = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $error = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    // partial: only one of two factors scored, by one judge only.
    $service->saveScores($judgeA, $partial, $version, [$factorA->id => 4]);

    // completed: both factors, by both judges (max = 2 judges * 2 factors = 4).
    $service->saveScores($judgeA, $completed, $version, [$factorA->id => 4, $factorB->id => 3]);
    $service->saveScores($judgeB, $completed, $version, [$factorA->id => 4, $factorB->id => 3]);

    // error: fully completed (4) plus one stray row from outside the rubric.
    $service->saveScores($judgeA, $error, $version, [$factorA->id => 4, $factorB->id => 3]);
    $service->saveScores($judgeB, $error, $version, [$factorA->id => 4, $factorB->id => 3]);
    Score::create([
        'version_id' => $version->id,
        'candidate_id' => $error->id,
        'student_id' => $error->student_id,
        'school_id' => $error->school_id,
        'score_category_id' => $strayCategory->id,
        'score_category_order_by' => $strayCategory->order_by,
        'score_factor_id' => $strayFactor->id,
        'score_factor_order_by' => $strayFactor->order_by,
        'judge_id' => $judgeA->id,
        'judge_order_by' => 1,
        'voice_part_id' => $error->voice_part_id,
        'voice_part_order_by' => $voicePart->sort_order,
        'score' => 2,
    ]);

    $candidateIds = collect([$none, $partial, $completed, $error])->pluck('id');
    $statuses = $service->candidateStatuses($room, $candidateIds);

    expect($statuses[$none->id])->toBe('none');
    expect($statuses[$partial->id])->toBe('partial');
    expect($statuses[$completed->id])->toBe('completed');
    expect($statuses[$error->id])->toBe('error');
});

test('candidateTolerances is true for a null room tolerance and for a candidate with no scores yet', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version, tolerance: null);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);

    $result = $service->candidateTolerances($room, collect([$candidate])->pluck('id'));

    expect($result[$candidate->id])->toBeTrue();
});

test('candidateTolerances flags a candidate whose judge totals exceed the room tolerance', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version, tolerance: 2);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = makeScoreFactor($version, $category);
    $room->scoreCategories()->attach($category->id);

    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($judgeA, $candidate, $version, [$factor->id => 5]);
    $service->saveScores($judgeB, $candidate, $version, [$factor->id => 1]);

    $result = $service->candidateTolerances($room, collect([$candidate])->pluck('id'));

    expect($result[$candidate->id])->toBeFalse();
});

test('candidateTolerances, candidateStatuses, candidateTotals, and scoresForCandidate ignore another room\'s judges scoring the same candidate', function () {
    // Regression test: a Candidate can be adjudicated in more than one Room
    // in the same Version (e.g. separate Scales/Solo rooms, each with its
    // own judge trio and rubric). Every Score aggregate must scope to the
    // Room's own judges, or a wildly different score from an unrelated
    // Room's judge corrupts this Room's tolerance/status/total for the same
    // candidate id.
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();

    $roomA = makeAdjudicationRoom($version, tolerance: 2);
    $roomB = makeAdjudicationRoom($version, tolerance: 2);

    $voicePart = VoicePart::factory()->create();
    $roomA->voiceParts()->attach($voicePart->id);

    $categoryA = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factorA = makeScoreFactor($version, $categoryA, ['description' => 'A', 'abbreviation' => 'A']);
    $roomA->scoreCategories()->attach($categoryA->id);

    $categoryB = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $factorB = makeScoreFactor($version, $categoryB, ['description' => 'B', 'abbreviation' => 'B']);
    $roomB->scoreCategories()->attach($categoryB->id);

    $judgeA1 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomA->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeA2 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomA->id, 'judge_type' => JudgeType::Judge2]);
    $judgeB1 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomB->id, 'judge_type' => JudgeType::HeadJudge]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    // Room A: two judges, close together (diff = 1), within its tolerance of 2.
    $service->saveScores($judgeA1, $candidate, $version, [$factorA->id => 4]);
    $service->saveScores($judgeA2, $candidate, $version, [$factorA->id => 5]);

    // Room B: an unrelated judge scores the SAME candidate far outside Room
    // A's range. Pre-fix, candidateTolerances($roomA, ...) pulled in this
    // score too (query scoped only by version_id + candidate_id), making the
    // spread 100 - 4 = 96 > tolerance 2 and wrongly flagging Room A as
    // out-of-tolerance because of a room it has nothing to do with.
    $service->saveScores($judgeB1, $candidate, $version, [$factorB->id => 100]);

    $candidateIds = collect([$candidate])->pluck('id');

    expect($service->candidateTolerances($roomA, $candidateIds)[$candidate->id])->toBeTrue();
    expect($service->candidateStatuses($roomA, $candidateIds)[$candidate->id])->toBe('completed');
    expect($service->candidateTotals($roomA, $candidateIds)[$candidate->id])->toBe(9); // 4 + 5, not +1 from Room B
    expect($service->scoresForCandidate($roomA, $candidate)->keys()->all())->toEqualCanonicalizing([$judgeA1->id, $judgeA2->id]);
});

test('candidateTotals sums every judge weighted by each factor multiplier', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factorA = makeScoreFactor($version, $category, ['description' => 'A', 'abbreviation' => 'A', 'multiplier' => 1]);
    $factorB = makeScoreFactor($version, $category, ['description' => 'B', 'abbreviation' => 'B', 'order_by' => 2, 'multiplier' => 2]);
    $room->scoreCategories()->attach($category->id);

    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    // judgeA: A=4, B=3 -> 4*1 + 3*2 = 10
    $service->saveScores($judgeA, $candidate, $version, [$factorA->id => 4, $factorB->id => 3]);
    // judgeB: A=2, B=1 -> 2*1 + 1*2 = 4
    $service->saveScores($judgeB, $candidate, $version, [$factorA->id => 2, $factorB->id => 1]);

    $totals = $service->candidateTotals($room, collect([$candidate])->pluck('id'));

    expect($totals[$candidate->id])->toBe(14);
});

test('candidateTotals is zero for a candidate with no scores yet', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);

    $totals = $service->candidateTotals($room, collect([$candidate])->pluck('id'));

    expect($totals[$candidate->id])->toBe(0);
});

test('roomProgress rolls candidateStatuses up into per-room bucket counts', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = makeScoreFactor($version, $category);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $none = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $completed = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($judge, $completed, $version, [$factor->id => 4]);

    $progress = $service->roomProgress($room);

    expect($progress)->toBe(['completed' => 1, 'partial' => 0, 'none' => 1, 'error' => 0]);
});

test('versionProgress rolls up roomProgress across every room in the version', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();

    $roomA = makeAdjudicationRoom($version);
    $roomB = makeAdjudicationRoom($version);

    $voicePartA = VoicePart::factory()->create();
    $voicePartB = VoicePart::factory()->create();
    $roomA->voiceParts()->attach($voicePartA->id);
    $roomB->voiceParts()->attach($voicePartB->id);

    Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePartA->id]);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePartB->id]);

    $progress = $service->versionProgress($version);

    expect($progress)->toHaveCount(2);
    expect($progress->pluck('room.id')->all())->toEqualCanonicalizing([$roomA->id, $roomB->id]);
    expect($progress->firstWhere('room.id', $roomA->id))->toMatchArray(['none' => 1, 'completed' => 0, 'partial' => 0, 'error' => 0]);
});

test('judgeCompletionFor is true only once the specific judge has scored every factor for a candidate', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factorA = makeScoreFactor($version, $category, ['description' => 'A', 'abbreviation' => 'A']);
    $factorB = makeScoreFactor($version, $category, ['description' => 'B', 'abbreviation' => 'B', 'order_by' => 2]);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $candidateIds = collect([$candidate])->pluck('id');

    $service->saveScores($judge, $candidate, $version, [$factorA->id => 4]);
    expect($service->judgeCompletionFor($judge, $candidateIds, 2)[$candidate->id])->toBeFalse();

    $service->saveScores($judge, $candidate, $version, [$factorA->id => 4, $factorB->id => 3]);
    expect($service->judgeCompletionFor($judge, $candidateIds, 2)[$candidate->id])->toBeTrue();
});

test('roomJudgesOrdered respects JudgeType declaration order regardless of creation order', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);

    $judge2 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);
    $head = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $monitor = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Monitor]);

    $ordered = $service->roomJudgesOrdered($room)->pluck('id')->all();

    expect($ordered)->toBe([$head->id, $judge2->id, $monitor->id]);
});

test('saveScores upserts on the natural key idempotently, producing no duplicate rows on repeated calls', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = makeScoreFactor($version, $category);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($judge, $candidate, $version, [$factor->id => 3]);
    $service->saveScores($judge, $candidate, $version, [$factor->id => 5]);

    $rows = Score::where('judge_id', $judge->id)->where('candidate_id', $candidate->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->score)->toBe(5);
});

test('optionsForFactor produces a best-first stepped range for both ascending and descending factors', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);

    $descending = makeScoreFactor($version, $category, ['best' => 5, 'worst' => 1, 'interval_by' => 1]);
    expect($service->optionsForFactor($descending))->toBe([5, 4, 3, 2, 1]);

    $ascending = makeScoreFactor($version, $category, ['best' => 1, 'worst' => 5, 'interval_by' => 2, 'order_by' => 2]);
    expect($service->optionsForFactor($ascending))->toBe([1, 3, 5]);
});

test('recordingsForCandidate returns only approved recordings matching the room rubric, ordered by category order_by', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id]);

    $soloCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $scalesCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $room->scoreCategories()->attach([$soloCategory->id, $scalesCategory->id]);

    $approver = User::factory()->create();

    $solo = Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Solo',
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/solo.mp3',
    ]);

    $scales = Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'scales', // lower-case — matched case-insensitively against "Scales"
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/scales.mp3',
    ]);

    // Unapproved — must never be returned.
    Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Solo',
        'uploaded_by' => $approver->id,
        'approved_at' => null,
        'approved_by' => null,
        'url' => 'recordings/unapproved-solo.mp3',
    ]);

    // file_type with no matching room category — must never be returned.
    Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Quintet',
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/quintet.mp3',
    ]);

    $recordings = $service->recordingsForCandidate($room, $candidate);

    expect($recordings->pluck('id')->all())->toBe([$scales->id, $solo->id]);
});
