<?php

declare(strict_types=1);

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
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
use App\Services\StudentMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers are studentMerge-prefixed: Pest test-file functions are global
// across the suite, so reusing another file's helper names would fatal-error a full run.

function studentMergePaidCandidate(Student $student, Version $version, int $amount = 2800): Candidate
{
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'version_id' => $version->id]);
    $transaction = PaymentTransaction::factory()->create([
        'version_id' => $version->id,
        'payer_student_id' => $student->id,
        'amount' => $amount,
    ]);
    PaymentAllocation::factory()->create([
        'payment_transaction_id' => $transaction->id,
        'candidate_id' => $candidate->id,
        'amount' => $amount,
    ]);

    return $candidate;
}

test('a loser candidate in a version the winner is not in moves to the winner with its payment', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $candidate = studentMergePaidCandidate($loser, $version);

    app(StudentMergeService::class)->merge($winner, $loser);

    $candidate->refresh();

    expect($candidate->student_id)->toBe($winner->id)
        ->and((int) $candidate->paymentAllocations()->sum('amount'))->toBe(2800)
        ->and(PaymentTransaction::where('payer_student_id', $winner->id)->count())->toBe(1)
        ->and(Student::find($loser->id))->toBeNull();
});

test('when both are candidates in the same version the winner candidate keeps the loser payment', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $winnerCandidate = Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = studentMergePaidCandidate($loser, $version);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect(Candidate::find($loserCandidate->id))->toBeNull()
        ->and((int) $winnerCandidate->paymentAllocations()->sum('amount'))->toBe(2800)
        ->and(Candidate::where('version_id', $version->id)->count())->toBe(1);
});

test('the merge reassigns an unallocated payment the loser made', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $transaction = PaymentTransaction::factory()->create(['payer_student_id' => $loser->id]);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect($transaction->fresh()?->payer_student_id)->toBe($winner->id);
});

test('scores on a moved candidate follow it to the winner', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $version->event_id, 'version_id' => null, 'score_category_id' => $category->id,
        'description' => 'High Scale', 'abbreviation' => 'HS', 'best' => 5, 'worst' => 1,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1,
    ]);
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Soprano I', 'order_by' => 1]);
    $roomJudge = RoomJudge::create([
        'version_id' => $version->id, 'room_id' => $room->id, 'user_id' => User::factory()->create()->id,
        'judge_type' => JudgeType::HeadJudge, 'status' => JudgeStatus::Assigned,
    ]);

    $score = Score::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'student_id' => $loser->id,
        'school_id' => $candidate->school_id,
        'score_category_id' => $category->id,
        'score_category_order_by' => 1,
        'score_factor_id' => $factor->id,
        'score_factor_order_by' => 1,
        'judge_id' => $roomJudge->id,
        'judge_order_by' => 1,
        'voice_part_id' => VoicePart::factory()->create()->id,
        'voice_part_order_by' => 1,
        'score' => 3,
    ]);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect(Score::find($score->id)?->student_id)->toBe($winner->id);
});

test('conflicts names the version where the loser candidate has adjudication data', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create(['name' => 'All-State Chorus 2027']);
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);

    Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $loserCandidate->id,
        'file_type' => 'Solo',
        'uploaded_by' => User::factory()->create()->id,
        'url' => 'recordings/solo.mp3',
    ]);

    expect(app(StudentMergeService::class)->conflicts($winner, $loser))->toBe(['All-State Chorus 2027']);
});

test('merge refuses and changes nothing when there is a conflict', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = studentMergePaidCandidate($loser, $version);

    Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $loserCandidate->id,
        'file_type' => 'Solo',
        'uploaded_by' => User::factory()->create()->id,
        'url' => 'recordings/solo.mp3',
    ]);

    expect(fn () => app(StudentMergeService::class)->merge($winner, $loser))->toThrow(DomainException::class);

    expect(Student::find($loser->id))->not->toBeNull()
        ->and(Candidate::find($loserCandidate->id))->not->toBeNull();
});

test('a same-version pair with no adjudication data on the loser is not a conflict', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    studentMergePaidCandidate($loser, $version);

    expect(app(StudentMergeService::class)->conflicts($winner, $loser))->toBe([]);
});
