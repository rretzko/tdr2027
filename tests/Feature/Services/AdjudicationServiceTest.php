<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
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

test('candidatesForRoom keeps a candidate visible after Ensemble Cut-offs resolves them, but not after they withdraw', function () {
    // Regression: candidatesForRoom() originally scoped to status=Registered
    // only. Once EnsembleCutoffService transitions a candidate straight
    // from Registered to accepted/not_accepted/no_show/incomplete, a
    // Registered-only scope silently dropped them from Adjudication
    // Tracking's roster and progress the instant a cutoff was applied for
    // their Voice Part. roomTrackingStates() must include all four
    // resolved outcomes, while still excluding genuinely-departed
    // candidates (withdrew/declined/removed).
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $accepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted]);
    $notAccepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NotAccepted]);
    $noShow = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NoShow]);
    $incomplete = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Incomplete]);
    $withdrew = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Withdrew]);

    $ids = $service->candidatesForRoom($room)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$accepted->id, $notAccepted->id, $noShow->id, $incomplete->id]);
    expect($ids)->not->toContain($withdrew->id);
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

test('roomHasOutOfToleranceCandidate is true only when a candidate in that room breaks its tolerance', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = makeScoreFactor($version, $category);

    $inToleranceRoom = makeAdjudicationRoom($version, tolerance: 2);
    $inToleranceRoom->voiceParts()->attach($voicePart->id);
    $inToleranceRoom->scoreCategories()->attach($category->id);
    $judgeA1 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $inToleranceRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeA2 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $inToleranceRoom->id, 'judge_type' => JudgeType::Judge2]);
    $inToleranceCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $service->saveScores($judgeA1, $inToleranceCandidate, $version, [$factor->id => 4]);
    $service->saveScores($judgeA2, $inToleranceCandidate, $version, [$factor->id => 5]);

    $outOfToleranceRoom = makeAdjudicationRoom($version, tolerance: 2);
    $outOfToleranceRoom->voiceParts()->attach($voicePart->id);
    $outOfToleranceRoom->scoreCategories()->attach($category->id);
    $judgeB1 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $outOfToleranceRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB2 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $outOfToleranceRoom->id, 'judge_type' => JudgeType::Judge2]);
    $outOfToleranceCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $service->saveScores($judgeB1, $outOfToleranceCandidate, $version, [$factor->id => 5]);
    $service->saveScores($judgeB2, $outOfToleranceCandidate, $version, [$factor->id => 1]);

    $emptyRoom = makeAdjudicationRoom($version, tolerance: 2);

    expect($service->roomHasOutOfToleranceCandidate($inToleranceRoom))->toBeFalse();
    expect($service->roomHasOutOfToleranceCandidate($outOfToleranceRoom))->toBeTrue();
    expect($service->roomHasOutOfToleranceCandidate($emptyRoom))->toBeFalse();
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

test('versionCandidateTotal sums candidateTotals across every Room the candidate is adjudicated in', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $voicePart = VoicePart::factory()->create();

    $scalesRoom = makeAdjudicationRoom($version);
    $scalesRoom->voiceParts()->attach($voicePart->id);
    $scalesCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $scalesFactor = makeScoreFactor($version, $scalesCategory, ['description' => 'Scales Tone', 'abbreviation' => 'ST']);
    $scalesRoom->scoreCategories()->attach($scalesCategory->id);
    $scalesJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $scalesRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    $soloRoom = makeAdjudicationRoom($version);
    $soloRoom->voiceParts()->attach($voicePart->id);
    $soloCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $soloFactor = makeScoreFactor($version, $soloCategory, ['description' => 'Solo Tone', 'abbreviation' => 'SO']);
    $soloRoom->scoreCategories()->attach($soloCategory->id);
    $soloJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $soloRoom->id, 'judge_type' => JudgeType::HeadJudge]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($scalesJudge, $candidate, $version, [$scalesFactor->id => 4]);
    $service->saveScores($soloJudge, $candidate, $version, [$soloFactor->id => 3]);

    expect($service->versionCandidateTotal($candidate))->toBe(7);
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

test('saveScores stamps overridden_by_user_id and overridden_at only when a Tab Room override is passed', function () {
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
    $row = Score::where('judge_id', $judge->id)->where('candidate_id', $candidate->id)->first();
    expect($row->overridden_by_user_id)->toBeNull();
    expect($row->overridden_at)->toBeNull();

    $tabRoomManager = User::factory()->create();
    $service->saveScores($judge, $candidate, $version, [$factor->id => 5], overriddenByUserId: $tabRoomManager->id);
    $row->refresh();
    expect($row->overridden_by_user_id)->toBe($tabRoomManager->id);
    expect($row->overridden_at)->not->toBeNull();
    expect($row->score)->toBe(5);
});

test('findCandidate matches by exact candidates.id, scoped to Registered status in this Version', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $registered = Candidate::factory()->registered()->create(['version_id' => $version->id]);
    $pending = Candidate::factory()->create(['version_id' => $version->id]); // not Registered

    $found = $service->findCandidate($version, (string) $registered->id, null);
    expect($found['candidate']->id)->toBe($registered->id);
    expect($found['matches'])->toBeEmpty();

    $notFound = $service->findCandidate($version, (string) $pending->id, null);
    expect($notFound['candidate'])->toBeNull();
    expect($notFound['matches'])->toBeEmpty();
});

test('findCandidate matches a single last-name hit directly, and returns a pick-list for multiple hits', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();

    $soloUser = User::factory()->create(['last_name' => 'Danner']);
    $soloStudent = Student::factory()->create(['user_id' => $soloUser->id]);
    $solo = Candidate::factory()->registered()->create(['version_id' => $version->id, 'student_id' => $soloStudent->id]);

    $dupUserA = User::factory()->create(['last_name' => 'Smith']);
    $dupStudentA = Student::factory()->create(['user_id' => $dupUserA->id]);
    $dupA = Candidate::factory()->registered()->create(['version_id' => $version->id, 'student_id' => $dupStudentA->id]);

    $dupUserB = User::factory()->create(['last_name' => 'Smith']);
    $dupStudentB = Student::factory()->create(['user_id' => $dupUserB->id]);
    $dupB = Candidate::factory()->registered()->create(['version_id' => $version->id, 'student_id' => $dupStudentB->id]);

    $single = $service->findCandidate($version, null, 'Danner');
    expect($single['candidate']->id)->toBe($solo->id);

    $multiple = $service->findCandidate($version, null, 'Smith');
    expect($multiple['candidate'])->toBeNull();
    expect($multiple['matches']->pluck('id')->all())->toEqualCanonicalizing([$dupA->id, $dupB->id]);
});

test('roomsForCandidate returns every Room whose voice parts include the candidate\'s, and none that don\'t', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $voicePart = VoicePart::factory()->create();
    $otherVoicePart = VoicePart::factory()->create();

    $scalesRoom = makeAdjudicationRoom($version);
    $scalesRoom->voiceParts()->attach($voicePart->id);

    $soloRoom = makeAdjudicationRoom($version);
    $soloRoom->voiceParts()->attach($voicePart->id);

    $unrelatedRoom = makeAdjudicationRoom($version);
    $unrelatedRoom->voiceParts()->attach($otherVoicePart->id);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $rooms = $service->roomsForCandidate($candidate)->pluck('id')->all();

    expect($rooms)->toEqualCanonicalizing([$scalesRoom->id, $soloRoom->id]);
});

test('changeVoicePart removes every Score for the candidate across every Room, and only non-relevant Recordings', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();

    $oldVoicePart = VoicePart::factory()->create();
    $newVoicePart = VoicePart::factory()->create();

    $oldRoom = makeAdjudicationRoom($version);
    $oldRoom->voiceParts()->attach($oldVoicePart->id);
    $oldCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $oldFactor = makeScoreFactor($version, $oldCategory, ['description' => 'Old', 'abbreviation' => 'OLD']);
    $oldRoom->scoreCategories()->attach($oldCategory->id);

    $newRoom = makeAdjudicationRoom($version);
    $newRoom->voiceParts()->attach($newVoicePart->id);
    $newCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $newRoom->scoreCategories()->attach($newCategory->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $oldRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $oldVoicePart->id]);

    $service->saveScores($judge, $candidate, $version, [$oldFactor->id => 4]);

    $approver = User::factory()->create();
    $relevantRecording = Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Solo', // matches the new voice part's Room rubric — kept
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/solo.mp3',
    ]);
    $nonRelevantRecording = Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Scales', // only matches the OLD voice part's Room — removed
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/scales.mp3',
    ]);

    $service->changeVoicePart($candidate, $newVoicePart);

    expect(Score::where('candidate_id', $candidate->id)->count())->toBe(0);
    expect(Recording::find($relevantRecording->id))->not->toBeNull();
    expect(Recording::find($nonRelevantRecording->id))->toBeNull();
    expect($candidate->fresh()->voice_part_id)->toBe($newVoicePart->id);
});

test('judgeScoreSummaryForCandidate reports entered count, factor count, and total per judge', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factorA = makeScoreFactor($version, $category, ['description' => 'A', 'abbreviation' => 'A']);
    $factorB = makeScoreFactor($version, $category, ['description' => 'B', 'abbreviation' => 'B', 'order_by' => 2]);
    $room->scoreCategories()->attach($category->id);

    $scoredJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $unscoredJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($scoredJudge, $candidate, $version, [$factorA->id => 4, $factorB->id => 3]);

    $summary = $service->judgeScoreSummaryForCandidate($room, $candidate)->keyBy(fn ($row) => $row['judge']->id);

    expect($summary[$scoredJudge->id]['enteredCount'])->toBe(2);
    expect($summary[$scoredJudge->id]['factorCount'])->toBe(2);
    expect($summary[$scoredJudge->id]['total'])->toBe(7);
    expect($summary[$unscoredJudge->id]['enteredCount'])->toBe(0);
    expect($summary[$unscoredJudge->id]['total'])->toBe(0);
});

test('candidateJudgeBreakdown matches judgeScoreSummaryForCandidate for the same room/candidate, aggregated across many candidates', function () {
    $service = app(AdjudicationService::class);
    $version = Version::factory()->create();
    $room = makeAdjudicationRoom($version);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = makeScoreFactor($version, $category);
    $room->scoreCategories()->attach($category->id);

    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $scored = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $unscored = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $service->saveScores($judge, $scored, $version, [$factor->id => 5]);

    $breakdown = $service->candidateJudgeBreakdown($room, collect([$scored, $unscored])->pluck('id'));

    $scoredRow = $breakdown[$scored->id]->firstWhere('judge.id', $judge->id);
    expect($scoredRow['enteredCount'])->toBe(1);
    expect($scoredRow['total'])->toBe(5);

    $unscoredRow = $breakdown[$unscored->id]->firstWhere('judge.id', $judge->id);
    expect($unscoredRow['enteredCount'])->toBe(0);
    expect($unscoredRow['total'])->toBe(0);
});
