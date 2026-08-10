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

test('SequentialEnsembleFillStrategy fills Ensembles in priority order via applyEnsembleCutoff, then finalizeVoicePart rejects the rest', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::SequentialEnsembleFill->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $first = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'First Choir']);
    $first->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $first->id, 'order_by' => 1]);

    $second = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Second Choir']);
    $second->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $second->id, 'order_by' => 2]);

    $top = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $middle = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $bottom = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    scoreCandidate($judge, $top, $version, $factor->id, 95);
    scoreCandidate($judge, $middle, $version, $factor->id, 75);
    scoreCandidate($judge, $bottom, $version, $factor->id, 40);

    // First Choir takes only the top scorer (cutoff 90).
    $cutoffs->applyEnsembleCutoff($version, $voicePart, $first, 90);
    expect($top->fresh()->accepted_ensemble_id)->toBe($first->id);
    expect($middle->fresh()->status)->toBe(CandidateStatus::Registered); // still unresolved, not rejected yet

    // Second Choir fills from the remaining pool (top is no longer Registered/ranked).
    $cutoffs->applyEnsembleCutoff($version, $voicePart, $second, 70);
    expect($middle->fresh()->accepted_ensemble_id)->toBe($second->id);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::Registered); // still unresolved

    $cutoffs->finalizeVoicePart($version, $voicePart);
    expect($bottom->fresh()->status)->toBe(CandidateStatus::NotAccepted);
});

test('applyEnsembleCutoff re-decision never poaches a candidate another Ensemble already accepted', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::SequentialEnsembleFill->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $first = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'First Choir']);
    $first->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $first->id, 'order_by' => 1]);

    $second = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Second Choir']);
    $second->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $second->id, 'order_by' => 2]);

    $claimedBySecond = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    scoreCandidate($judge, $claimedBySecond, $version, $factor->id, 80);

    $cutoffs->applyEnsembleCutoff($version, $voicePart, $second, 70);
    expect($claimedBySecond->fresh()->accepted_ensemble_id)->toBe($second->id);

    // First Choir widens its cutoff low enough to otherwise include
    // $claimedBySecond's score — it must stay with Second Choir.
    $cutoffs->applyEnsembleCutoff($version, $voicePart, $first, 50);

    expect($claimedBySecond->fresh()->accepted_ensemble_id)->toBe($second->id);
    expect($claimedBySecond->fresh()->status)->toBe(CandidateStatus::Accepted);
});

test('GradeSegmentedEnsemblesStrategy partitions candidates by grade before applying each Ensemble\'s own cutoff', function () {
    $cutoffs = app(EnsembleCutoffService::class);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::GradeSegmentedEnsembles->value]);
    $voicePart = VoicePart::factory()->create();
    ['factor' => $factor, 'judge' => $judge] = makeCutoffRoom($version, $voicePart);

    $junior = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Junior Choir']);
    $junior->voiceParts()->attach($voicePart->id);
    EnsembleGrade::create(['ensemble_id' => $junior->id, 'grade' => 7]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $junior->id, 'order_by' => 1]);

    $senior = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Senior Choir']);
    $senior->voiceParts()->attach($voicePart->id);
    EnsembleGrade::create(['ensemble_id' => $senior->id, 'grade' => 11]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $senior->id, 'order_by' => 2]);

    // A 7th-grader who outscores the 11th-grader must still only ever compete within their own grade partition.
    $seventhGrader = candidateWithGrade($version, $voicePart, 7);
    $eleventhGrader = candidateWithGrade($version, $voicePart, 11);
    scoreCandidate($judge, $seventhGrader, $version, $factor->id, 95);
    scoreCandidate($judge, $eleventhGrader, $version, $factor->id, 60);

    $cutoffs->applyEnsembleCutoff($version, $voicePart, $junior, 90);
    $cutoffs->applyEnsembleCutoff($version, $voicePart, $senior, 50);

    expect($seventhGrader->fresh()->accepted_ensemble_id)->toBe($junior->id);
    expect($eleventhGrader->fresh()->accepted_ensemble_id)->toBe($senior->id);
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
