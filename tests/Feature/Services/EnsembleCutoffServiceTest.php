<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleCutoff;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\EnsembleCutoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => actingAs(User::factory()->create()));

/**
 * A one-Room, one-factor rubric on $version for $voicePart, plus a helper
 * to fully score a Candidate through it. Mirrors AdjudicationServiceTest's
 * makeAdjudicationRoom()/makeScoreFactor() conventions.
 */
function makeCutoffRoom(Version $version, VoicePart $voicePart): array
{
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $version->event_id,
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

    return compact('room', 'factor', 'judge');
}

function scoreCandidate(RoomJudge $judge, Candidate $candidate, Version $version, int $factorId, int $score): void
{
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factorId => $score]);
}

/**
 * A Candidate with an active school enrollment giving them $grade, for
 * GradeSegmentedEnsembles tests.
 */
function candidateWithGrade(Version $version, VoicePart $voicePart, int $grade): Candidate
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year + (12 - $grade)]);

    return Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'student_id' => $student->id,
        'school_id' => $school->id,
    ]);
}

test('voicePartCompletion combines statuses across every Room serving the Voice Part', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create();

    // Two Rooms for the same Voice Part (e.g. Scales + Solo).
    $roomA = VersionRoom::create(['version_id' => $version->id, 'name' => 'A', 'order_by' => 1]);
    $roomA->voiceParts()->attach($voicePart->id);
    $categoryA = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'A', 'order_by' => 1]);
    $factorA = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $categoryA->id, 'description' => 'A', 'abbreviation' => 'A', 'best' => 5, 'worst' => 1, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $roomA->scoreCategories()->attach($categoryA->id);
    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomA->id, 'judge_type' => JudgeType::HeadJudge]);

    $roomB = VersionRoom::create(['version_id' => $version->id, 'name' => 'B', 'order_by' => 2]);
    $roomB->voiceParts()->attach($voicePart->id);
    $categoryB = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'B', 'order_by' => 2]);
    $factorB = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $categoryB->id, 'description' => 'B', 'abbreviation' => 'B', 'best' => 5, 'worst' => 1, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $roomB->scoreCategories()->attach($categoryB->id);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $roomB->id, 'judge_type' => JudgeType::HeadJudge]);

    $completeInBoth = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $completeInOnlyOne = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $completeInNeither = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judgeA, $completeInBoth, $version, $factorA->id, 4);
    scoreCandidate($judgeB, $completeInBoth, $version, $factorB->id, 4);

    scoreCandidate($judgeA, $completeInOnlyOne, $version, $factorA->id, 4);
    // Room B left unscored for $completeInOnlyOne.

    $ids = collect([$completeInBoth, $completeInOnlyOne, $completeInNeither])->pluck('id');
    $completion = $cutoffs->voicePartCompletion($version, $voicePart, $ids);

    expect($completion[$completeInBoth->id])->toBe('completed');
    expect($completion[$completeInOnlyOne->id])->toBe('partial');
    expect($completion[$completeInNeither->id])->toBe('none');
});

test('rankedCandidates includes only completed candidates, sorted best-to-worst per score_order', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $ascVersion = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'asc']);
    $voicePart = VoicePart::factory()->create();
    ['room' => $room, 'factor' => $factor, 'judge' => $judge] = makeCutoffRoom($ascVersion, $voicePart);

    $low = Candidate::factory()->registered()->create(['version_id' => $ascVersion->id, 'voice_part_id' => $voicePart->id]);
    $high = Candidate::factory()->registered()->create(['version_id' => $ascVersion->id, 'voice_part_id' => $voicePart->id]);
    $unscored = Candidate::factory()->registered()->create(['version_id' => $ascVersion->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judge, $low, $ascVersion, $factor->id, 20);
    scoreCandidate($judge, $high, $ascVersion, $factor->id, 90);

    $ranked = $cutoffs->rankedCandidates($ascVersion, $voicePart);

    expect($ranked->pluck('candidate.id')->all())->toBe([$low->id, $high->id]); // ascending: lower total first = "best"
    expect($ranked->pluck('total')->all())->toBe([20, 90]);
});

test('transitionNonCompletedCandidates moves none/partial to no_show/incomplete and leaves completed as Registered', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $none = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $completed = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    scoreCandidate($judge, $completed, $version, $factor->id, 50);

    $cutoffs->transitionNonCompletedCandidates($version, $voicePart);

    expect($none->fresh()->status)->toBe(CandidateStatus::NoShow);
    expect($completed->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('PerVoicePartPerEnsembleStrategy via applyCutoff accepts at/above cutoff and rejects the rest, writing AuditionResult', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $above = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $atCutoff = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $below = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judge, $above, $version, $factor->id, 90);
    scoreCandidate($judge, $atCutoff, $version, $factor->id, 70);
    scoreCandidate($judge, $below, $version, $factor->id, 50);

    $cutoffs->applyCutoff($version, $voicePart, 70);

    expect($above->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($above->fresh()->accepted_ensemble_id)->toBe($ensemble->id);
    expect($atCutoff->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($below->fresh()->status)->toBe(CandidateStatus::NotAccepted);
    expect($below->fresh()->accepted_ensemble_id)->toBeNull();

    $result = AuditionResult::where('candidate_id', $above->id)->first();
    expect($result->total)->toBe(90);
    expect($result->score_count)->toBe(1);
});

test('applyCutoff can be reapplied against a different score without ever reverting anyone to Registered', function () {
    // "Reopen" is a pure UI toggle — the service must support re-deciding a
    // Voice Part in place. Moving the cutoff up should flip a
    // previously-Accepted candidate to NotAccepted (and vice versa moving
    // it down), never bouncing anyone through Registered in between.
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $borderline = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $alwaysIn = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $noShow = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judge, $borderline, $version, $factor->id, 75);
    scoreCandidate($judge, $alwaysIn, $version, $factor->id, 95);
    // $noShow left unscored.

    $cutoffs->applyCutoff($version, $voicePart, 70);
    expect($borderline->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($borderline->fresh()->accepted_ensemble_id)->toBe($ensemble->id);
    expect($noShow->fresh()->status)->toBe(CandidateStatus::NoShow);

    // Raise the cutoff above $borderline's score — they should flip to NotAccepted.
    $cutoffs->applyCutoff($version, $voicePart, 80);
    expect($borderline->fresh()->status)->toBe(CandidateStatus::NotAccepted);
    expect($borderline->fresh()->accepted_ensemble_id)->toBeNull();
    expect($alwaysIn->fresh()->status)->toBe(CandidateStatus::Accepted);
    // AuditionResult is kept up to date, not deleted.
    expect(AuditionResult::where('candidate_id', $borderline->id)->value('total'))->toBe(75);

    // Lower it back down — $borderline is accepted again.
    $cutoffs->applyCutoff($version, $voicePart, 70);
    expect($borderline->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($borderline->fresh()->accepted_ensemble_id)->toBe($ensemble->id);

    // no_show is untouched by any of this — it's a scoring fact, not a cutoff decision.
    expect($noShow->fresh()->status)->toBe(CandidateStatus::NoShow);
});

test('AlternatingEnsembleAssignmentStrategy round-robins candidates at/above cutoff between two Ensembles by rank', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $ensembleA = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble']);
    $ensembleA->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensembleA->id, 'order_by' => 1]);

    $ensembleB = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed']);
    $ensembleB->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensembleB->id, 'order_by' => 2]);

    // Ranked (desc) order: rank0=c1(100), rank1=c2(90), rank2=c3(80), rank3=c4(50, below cutoff of 70).
    $c1 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $c2 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $c3 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $c4 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judge, $c1, $version, $factor->id, 100);
    scoreCandidate($judge, $c2, $version, $factor->id, 90);
    scoreCandidate($judge, $c3, $version, $factor->id, 80);
    scoreCandidate($judge, $c4, $version, $factor->id, 50);

    $cutoffs->applyCutoff($version, $voicePart, 70);

    expect($c1->fresh()->accepted_ensemble_id)->toBe($ensembleA->id); // rank 0 -> A
    expect($c2->fresh()->accepted_ensemble_id)->toBe($ensembleB->id); // rank 1 -> B
    expect($c3->fresh()->accepted_ensemble_id)->toBe($ensembleA->id); // rank 2 -> A
    expect($c4->fresh()->status)->toBe(CandidateStatus::NotAccepted); // below cutoff
});

test('AlternatingEnsembleAssignmentStrategy rotates by distinct score, keeping every tied candidate together in the same Ensemble', function () {
    // Product clarification: alternation assigns by score, not by
    // candidate — three candidates tied at 100 all land in the same
    // Ensemble as a group; the next distinct score's candidates all land
    // in the next Ensemble, regardless of how many are tied at each score.
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'asc', 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $treble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble']);
    $treble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $treble->id, 'order_by' => 1]);

    $mixed = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed']);
    $mixed->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $mixed->id, 'order_by' => 2]);

    // Three candidates tied at 100 (the better score, ascending order).
    $tiedAt100 = Candidate::factory()->registered()->count(3)->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    foreach ($tiedAt100 as $candidate) {
        scoreCandidate($judge, $candidate, $version, $factor->id, 100);
    }

    // Four candidates tied at 101 (the next distinct, worse score).
    $tiedAt101 = Candidate::factory()->registered()->count(4)->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    foreach ($tiedAt101 as $candidate) {
        scoreCandidate($judge, $candidate, $version, $factor->id, 101);
    }

    $cutoffs->applyCutoff($version, $voicePart, 101);

    foreach ($tiedAt100 as $candidate) {
        expect($candidate->fresh()->accepted_ensemble_id)->toBe($treble->id);
    }

    foreach ($tiedAt101 as $candidate) {
        expect($candidate->fresh()->accepted_ensemble_id)->toBe($mixed->id);
    }
});

test('AlternatingEnsembleAssignmentStrategy degenerates to single-Ensemble behavior for a Voice Part served by only one eligible Ensemble', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment->value]);
    $sharedVoicePart = VoicePart::factory()->create(); // e.g. Soprano — both ensembles
    $exclusiveVoicePart = VoicePart::factory()->create(); // e.g. Tenor — Mixed Chorus only
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $exclusiveVoicePart);

    $treble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble']);
    $treble->voiceParts()->attach($sharedVoicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $treble->id, 'order_by' => 1]);

    $mixed = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed']);
    $mixed->voiceParts()->attach([$sharedVoicePart->id, $exclusiveVoicePart->id]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $mixed->id, 'order_by' => 2]);

    $tenor1 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $exclusiveVoicePart->id]);
    $tenor2 = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $exclusiveVoicePart->id]);
    scoreCandidate($judge, $tenor1, $version, $factor->id, 90);
    scoreCandidate($judge, $tenor2, $version, $factor->id, 80);

    $cutoffs->applyCutoff($version, $exclusiveVoicePart, 70);

    expect($tenor1->fresh()->accepted_ensemble_id)->toBe($mixed->id);
    expect($tenor2->fresh()->accepted_ensemble_id)->toBe($mixed->id); // both go to Mixed — no alternation, only one eligible Ensemble
});

/**
 * A desc-scored Version with two Ensembles (First, Second — in that
 * ensembleOrder priority) both serving one Voice Part, for the per-Ensemble
 * strategies' click flow.
 *
 * @return array{version: Version, voicePart: VoicePart, first: Ensemble, second: Ensemble, factor: ScoreFactor, judge: RoomJudge, room: VersionRoom}
 */
function makeTwoEnsemblePerEnsembleVersion(CutoffStrategy $strategy): array
{
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => $strategy->value]);
    $voicePart = VoicePart::factory()->create();
    ['room' => $room, 'factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $first = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'First Choir']);
    $first->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $first->id, 'order_by' => 1]);

    $second = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Second Choir']);
    $second->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $second->id, 'order_by' => 2]);

    return compact('version', 'voicePart', 'first', 'second', 'factor', 'judge', 'room');
}

/**
 * Four scored candidates (95, 85, 75, 40) in the fixture's Voice Part.
 *
 * @return array{top: Candidate, upper: Candidate, lower: Candidate, bottom: Candidate}
 */
function makeFourRankedCandidates(array $fixture): array
{
    $rows = [];

    foreach (['top' => 95, 'upper' => 85, 'lower' => 75, 'bottom' => 40] as $name => $score) {
        $rows[$name] = Candidate::factory()->registered()->create(['version_id' => $fixture['version']->id, 'voice_part_id' => $fixture['voicePart']->id]);
        scoreCandidate($fixture['judge'], $rows[$name], $fixture['version'], $fixture['factor']->id, $score);
    }

    return $rows;
}

test('sequential: the first click sets the top Ensemble\'s line, a click below it sets the next Ensemble\'s, and finalizeAll rejects the rest', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart, 'first' => $first, 'second' => $second] = $fixture;
    ['top' => $top, 'upper' => $upper, 'lower' => $lower, 'bottom' => $bottom] = makeFourRankedCandidates($fixture);

    $result = $cutoffs->applyScoreClick($version, $voicePart, $upper->id);
    expect($result['ensemble']->id)->toBe($first->id);
    expect($result['score'])->toBe(85);
    expect($top->fresh()->accepted_ensemble_id)->toBe($first->id);
    expect($upper->fresh()->accepted_ensemble_id)->toBe($first->id);
    expect($lower->fresh()->status)->toBe(CandidateStatus::Registered); // undecided, not rejected

    $result = $cutoffs->applyScoreClick($version, $voicePart, $lower->id);
    expect($result['ensemble']->id)->toBe($second->id);
    expect($lower->fresh()->accepted_ensemble_id)->toBe($second->id);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::Registered);

    expect(VersionEnsembleCutoff::where('version_id', $version->id)->pluck('score', 'ensemble_id')->all())
        ->toBe([$first->id => 85, $second->id => 75]);

    expect($cutoffs->finalizeAll($version))->toBe([]);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::NotAccepted);
    expect($top->fresh()->status)->toBe(CandidateStatus::Accepted);
});

test('sequential: clicking inside an Ensemble\'s band moves that Ensemble\'s line and re-flows freed candidates into the next Ensemble', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart, 'first' => $first, 'second' => $second] = $fixture;
    ['top' => $top, 'upper' => $upper, 'lower' => $lower] = makeFourRankedCandidates($fixture);

    $cutoffs->applyScoreClick($version, $voicePart, $upper->id); // First: 85
    $cutoffs->applyScoreClick($version, $voicePart, $lower->id); // Second: 75

    // $top is in First's band — raises First's line to 95; $upper falls through to Second.
    $result = $cutoffs->applyScoreClick($version, $voicePart, $top->id);
    expect($result['ensemble']->id)->toBe($first->id);
    expect($top->fresh()->accepted_ensemble_id)->toBe($first->id);
    expect($upper->fresh()->accepted_ensemble_id)->toBe($second->id);
    expect($lower->fresh()->accepted_ensemble_id)->toBe($second->id);

    // $upper is now in Second's band — raises Second's line to 85; $lower goes back to undecided.
    $result = $cutoffs->applyScoreClick($version, $voicePart, $upper->id);
    expect($result['ensemble']->id)->toBe($second->id);
    expect($lower->fresh()->status)->toBe(CandidateStatus::Registered);
    expect($lower->fresh()->accepted_ensemble_id)->toBeNull();
    expect(AuditionResult::where('candidate_id', $lower->id)->exists())->toBeFalse();
});

test('sequential: once every Ensemble has a line, clicking below them all moves the last Ensemble\'s line down', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart, 'second' => $second] = $fixture;
    ['upper' => $upper, 'lower' => $lower, 'bottom' => $bottom] = makeFourRankedCandidates($fixture);

    $cutoffs->applyScoreClick($version, $voicePart, $upper->id);
    $cutoffs->applyScoreClick($version, $voicePart, $lower->id);
    $result = $cutoffs->applyScoreClick($version, $voicePart, $bottom->id);

    expect($result['ensemble']->id)->toBe($second->id);
    expect($bottom->fresh()->accepted_ensemble_id)->toBe($second->id);
    expect(VersionEnsembleCutoff::where('ensemble_id', $second->id)->value('score'))->toBe(40);
});

test('a moved line accepts a candidate who was already finalized as not accepted', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart, 'second' => $second] = $fixture;
    ['upper' => $upper, 'lower' => $lower, 'bottom' => $bottom] = makeFourRankedCandidates($fixture);

    $cutoffs->applyScoreClick($version, $voicePart, $upper->id);
    $cutoffs->applyScoreClick($version, $voicePart, $lower->id);
    $cutoffs->finalizeAll($version);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::NotAccepted);

    $cutoffs->applyScoreClick($version, $voicePart, $bottom->id);

    expect($bottom->fresh()->status)->toBe(CandidateStatus::Accepted);
    expect($bottom->fresh()->accepted_ensemble_id)->toBe($second->id);
});

test('grade-segmented: a click targets the Ensemble the clicked candidate\'s grade belongs to, and lines never cross grades', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::GradeSegmentedEnsembles);
    ['version' => $version, 'voicePart' => $voicePart, 'first' => $junior, 'second' => $senior, 'judge' => $judge, 'factor' => $factor] = $fixture;
    EnsembleGrade::create(['ensemble_id' => $junior->id, 'grade' => 7]);
    EnsembleGrade::create(['ensemble_id' => $senior->id, 'grade' => 11]);

    $strongSeventh = candidateWithGrade($version, $voicePart, 7);
    $weakSeventh = candidateWithGrade($version, $voicePart, 7);
    $eleventh = candidateWithGrade($version, $voicePart, 11);
    scoreCandidate($judge, $strongSeventh, $version, $factor->id, 95);
    scoreCandidate($judge, $weakSeventh, $version, $factor->id, 30);
    scoreCandidate($judge, $eleventh, $version, $factor->id, 60);

    $result = $cutoffs->applyScoreClick($version, $voicePart, $eleventh->id);
    expect($result['ensemble']->id)->toBe($senior->id);
    expect($eleventh->fresh()->accepted_ensemble_id)->toBe($senior->id);
    expect($strongSeventh->fresh()->status)->toBe(CandidateStatus::Registered); // outscores the line, but wrong grade

    $result = $cutoffs->applyScoreClick($version, $voicePart, $strongSeventh->id);
    expect($result['ensemble']->id)->toBe($junior->id);
    expect($strongSeventh->fresh()->accepted_ensemble_id)->toBe($junior->id);

    expect($cutoffs->finalizeAll($version))->toBe([]);
    expect($weakSeventh->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('applyScoreClick returns null and changes nothing when no eligible Ensemble accepts the candidate\'s grade', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::GradeSegmentedEnsembles);
    ['version' => $version, 'voicePart' => $voicePart, 'first' => $junior, 'second' => $senior, 'judge' => $judge, 'factor' => $factor] = $fixture;
    EnsembleGrade::create(['ensemble_id' => $junior->id, 'grade' => 7]);
    EnsembleGrade::create(['ensemble_id' => $senior->id, 'grade' => 11]);

    $ninth = candidateWithGrade($version, $voicePart, 9);
    scoreCandidate($judge, $ninth, $version, $factor->id, 90);

    expect($cutoffs->applyScoreClick($version, $voicePart, $ninth->id))->toBeNull();
    expect(VersionEnsembleCutoff::count())->toBe(0);
    expect($cutoffs->finalizeSummary($version)['problems'])->toContain("{$voicePart->abbr}: 1 candidate in a grade no Ensemble for this voice part accepts.");
});

test('resetVoicePart clears its lines and returns every decided candidate to Registered', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart] = $fixture;
    ['top' => $top, 'lower' => $lower, 'bottom' => $bottom] = makeFourRankedCandidates($fixture);

    $cutoffs->applyScoreClick($version, $voicePart, $top->id);
    $cutoffs->applyScoreClick($version, $voicePart, $lower->id);
    $cutoffs->finalizeAll($version);

    $cutoffs->resetVoicePart($version, $voicePart);

    expect(VersionEnsembleCutoff::count())->toBe(0);
    foreach ([$top, $lower, $bottom] as $candidate) {
        expect($candidate->fresh()->status)->toBe(CandidateStatus::Registered);
        expect($candidate->fresh()->accepted_ensemble_id)->toBeNull();
    }
    expect(AuditionResult::where('version_id', $version->id)->count())->toBe(0);
});

test('finalizeAll refuses, changing nothing, while an Ensemble that could still claim candidates has no line', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart] = $fixture;
    ['upper' => $upper, 'bottom' => $bottom] = makeFourRankedCandidates($fixture);

    expect($cutoffs->finalizeAll($version))->toBe([
        "{$voicePart->abbr}: no cutoff selected yet for First Choir.",
        "{$voicePart->abbr}: no cutoff selected yet for Second Choir.",
    ]);

    $cutoffs->applyScoreClick($version, $voicePart, $upper->id);
    expect($cutoffs->finalizeAll($version))->toBe(["{$voicePart->abbr}: no cutoff selected yet for Second Choir."]);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::Registered);
});

test('finalizeAll does not demand a line for a later Ensemble when every candidate is already claimed', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart] = $fixture;
    ['bottom' => $bottom] = makeFourRankedCandidates($fixture);

    $cutoffs->applyScoreClick($version, $voicePart, $bottom->id); // First takes everyone

    expect($cutoffs->finalizeAll($version))->toBe([]);
});

test('finalizeAll refuses on partially scored and out-of-tolerance candidates, and marks unscored candidates No Show once clear', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $fixture = makeTwoEnsemblePerEnsembleVersion(CutoffStrategy::SequentialEnsembleFill);
    ['version' => $version, 'voicePart' => $voicePart, 'room' => $room, 'judge' => $judge, 'factor' => $factor] = $fixture;
    $room->update(['tolerance' => 10]);
    $judge2 = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

    $make = fn (): Candidate => Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $clean = $make();
    scoreCandidate($judge, $clean, $version, $factor->id, 80);
    scoreCandidate($judge2, $clean, $version, $factor->id, 82);
    $partial = $make();
    scoreCandidate($judge, $partial, $version, $factor->id, 70);
    $outOfTolerance = $make();
    scoreCandidate($judge, $outOfTolerance, $version, $factor->id, 90);
    scoreCandidate($judge2, $outOfTolerance, $version, $factor->id, 50);
    $unscored = $make();

    $cutoffs->applyScoreClick($version, $voicePart, $clean->id);
    $cutoffs->applyScoreClick($version, $voicePart, $outOfTolerance->id); // below First's line → Second's line

    $summary = $cutoffs->finalizeSummary($version);
    expect($summary['problems'])->toBe([
        "{$voicePart->abbr}: 1 candidate only partially scored.",
        "{$voicePart->abbr}: 1 candidate out of tolerance.",
    ]);
    expect($cutoffs->finalizeAll($version))->toBe($summary['problems']);
    expect($unscored->fresh()->status)->toBe(CandidateStatus::Registered);

    // Fix both, then finalize. Lines are First 162 (clean), Second 140 —
    // finalizeAll() recomputes from the edited scores, so $partial (now
    // 142) is claimed by Second and $outOfTolerance (now 178) by First,
    // instead of being rejected on their stale Registered/Second statuses.
    scoreCandidate($judge2, $partial, $version, $factor->id, 72);
    scoreCandidate($judge2, $outOfTolerance, $version, $factor->id, 88);

    expect($cutoffs->finalizeSummary($version))->toBe(['problems' => [], 'rejectCount' => 0, 'noShowCount' => 1]);
    expect($cutoffs->finalizeAll($version))->toBe([]);
    expect($unscored->fresh()->status)->toBe(CandidateStatus::NoShow);
    expect($partial->fresh()->accepted_ensemble_id)->toBe($fixture['second']->id);
    expect($outOfTolerance->fresh()->accepted_ensemble_id)->toBe($fixture['first']->id);
});

test('eligibleEnsembles is scoped to Ensembles whose voiceParts include the given Voice Part, in ensembleOrder priority', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $sopranoVoicePart = VoicePart::factory()->create();
    $tenorVoicePart = VoicePart::factory()->create();

    $treble = Ensemble::factory()->create(['event_id' => $event->id]);
    $treble->voiceParts()->attach($sopranoVoicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $treble->id, 'order_by' => 2]);

    $mixed = Ensemble::factory()->create(['event_id' => $event->id]);
    $mixed->voiceParts()->attach([$sopranoVoicePart->id, $tenorVoicePart->id]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $mixed->id, 'order_by' => 1]);

    expect($cutoffs->eligibleEnsembles($version, $sopranoVoicePart)->pluck('id')->all())->toBe([$mixed->id, $treble->id]);
    expect($cutoffs->eligibleEnsembles($version, $tenorVoicePart)->pluck('id')->all())->toBe([$mixed->id]);
});

test('acceptedCounts aggregates accepted candidates by Ensemble and Voice Part', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);

    Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NotAccepted]);

    $counts = $cutoffs->acceptedCounts($version);

    expect($counts)->toHaveCount(1);
    expect($counts->first()['count'])->toBe(2);
    expect($counts->first()['ensemble']->id)->toBe($ensemble->id);
});
