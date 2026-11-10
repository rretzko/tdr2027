<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\PhoneType;
use App\Enums\ShirtSize;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\Instrument;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Phone;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
use App\Models\Teacher;
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

function studentMergeStudentWithCell(?string $cell): Student
{
    return Student::factory()->create(['user_id' => User::factory()->create(['cell_phone' => $cell])->id]);
}

test('the loser account cell phone moves to a winner that has none', function () {
    $winner = studentMergeStudentWithCell(null);
    $loser = studentMergeStudentWithCell('2015551234');

    app(StudentMergeService::class)->merge($winner, $loser);

    expect($winner->user->fresh()?->cell_phone)->toBe('2015551234')
        ->and(User::find($loser->user_id))->toBeNull();
});

test('the winner keeps its own account cell phone and the loser one is reported as discarded', function () {
    $winner = studentMergeStudentWithCell('2015550000');
    $loser = studentMergeStudentWithCell('2015551234');
    $service = app(StudentMergeService::class);

    expect($service->discardedPhones($winner, $loser))->toBe(['(201) 555-1234']);

    $service->merge($winner, $loser);

    expect($winner->user->fresh()?->cell_phone)->toBe('2015550000');
});

test('a self-registered phones row moves to the winner when the winner has none of that type', function () {
    $winner = studentMergeStudentWithCell(null);
    $loser = studentMergeStudentWithCell(null);
    $phone = Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect($phone->fresh()?->user_id)->toBe($winner->user_id);
});

test('a loser phones row is dropped when the winner already has that type, and only a different number counts as discarded', function () {
    $winner = studentMergeStudentWithCell(null);
    $loser = studentMergeStudentWithCell(null);
    Phone::factory()->create(['user_id' => $winner->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015550000']);
    Phone::factory()->create(['user_id' => $winner->user_id, 'type' => PhoneType::Home, 'raw_number' => '2015557777']);
    Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);
    Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Home, 'raw_number' => '2015557777']);
    $service = app(StudentMergeService::class);

    expect($service->discardedPhones($winner, $loser))->toBe(['(201) 555-1234']);

    $service->merge($winner, $loser);

    expect(Phone::where('user_id', $winner->user_id)->orderBy('type')->pluck('raw_number')->all())->toBe(['2015550000', '2015557777'])
        ->and(Phone::where('user_id', $loser->user_id)->count())->toBe(0);
});

test('a loser number the winner holds in the other store is not counted as discarded', function () {
    $winner = studentMergeStudentWithCell('2015551234');
    $loser = studentMergeStudentWithCell(null);
    Phone::factory()->create(['user_id' => $winner->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015550000']);
    Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);

    expect(app(StudentMergeService::class)->discardedPhones($winner, $loser))->toBe([]);
});

test('phones stay with a loser User that survives because it has a Teacher record', function () {
    $winner = studentMergeStudentWithCell(null);
    $loser = studentMergeStudentWithCell('2015551234');
    Teacher::factory()->create(['user_id' => $loser->user_id]);
    $phone = Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015559999']);
    $service = app(StudentMergeService::class);

    expect($service->discardedPhones($winner, $loser))->toBe([]);

    $service->merge($winner, $loser);

    expect($loser->user->fresh()?->cell_phone)->toBe('2015551234')
        ->and($phone->fresh()?->user_id)->toBe($loser->user_id)
        ->and($winner->user->fresh()?->cell_phone)->toBeNull();
});

function studentMergeProfile(array $attributes): Student
{
    return Student::factory()->create($attributes + [
        'birthday' => null,
        'height' => null,
        'instrument_id' => null,
        'voice_part_id' => null,
        'shirt_size' => ShirtSize::MED,
    ]);
}

test('profileDifferences lists every field both students have set to different values', function () {
    $soprano = VoicePart::factory()->create(['name' => 'Soprano I']);
    $alto = VoicePart::factory()->create(['name' => 'Alto II']);
    $flute = Instrument::factory()->create(['name' => 'Flute']);
    $oboe = Instrument::factory()->create(['name' => 'Oboe']);
    $winner = studentMergeProfile(['birthday' => '2010-01-01', 'voice_part_id' => $soprano->id, 'height' => 60, 'instrument_id' => $flute->id, 'shirt_size' => ShirtSize::MED]);
    $loser = studentMergeProfile(['birthday' => '2010-02-02', 'voice_part_id' => $alto->id, 'height' => 64, 'instrument_id' => $oboe->id, 'shirt_size' => ShirtSize::XL]);
    $service = app(StudentMergeService::class);

    expect($service->profileDifferences($winner, $loser))->toBe([
        ['field' => 'Birthday', 'winner' => 'Jan 1, 2010', 'loser' => 'Feb 2, 2010'],
        ['field' => 'Voice part', 'winner' => 'Soprano I', 'loser' => 'Alto II'],
        ['field' => 'Height', 'winner' => '60"', 'loser' => '64"'],
        ['field' => 'Instrument', 'winner' => 'Flute', 'loser' => 'Oboe'],
        ['field' => 'Shirt size', 'winner' => 'Medium', 'loser' => 'X-Large'],
    ]);

    $service->merge($winner, $loser);

    $winner->refresh();

    expect($winner->voice_part_id)->toBe($soprano->id)
        ->and($winner->height)->toBe(60)
        ->and($winner->instrument_id)->toBe($flute->id)
        ->and($winner->getRawOriginal('shirt_size'))->toBe(ShirtSize::MED->value);
});

test('profileDifferences ignores fields that match or that only one student has', function () {
    $alto = VoicePart::factory()->create(['name' => 'Alto II']);
    $flute = Instrument::factory()->create(['name' => 'Flute']);
    $winner = studentMergeProfile(['voice_part_id' => $alto->id, 'height' => 60, 'shirt_size' => ShirtSize::LG]);
    $loser = studentMergeProfile(['voice_part_id' => $alto->id, 'birthday' => '2010-01-01', 'instrument_id' => $flute->id, 'shirt_size' => ShirtSize::LG]);

    expect(app(StudentMergeService::class)->profileDifferences($winner, $loser))->toBe([]);
});

test('the merge fills an empty winner instrument from the loser', function () {
    $flute = Instrument::factory()->create();
    $winner = studentMergeProfile([]);
    $loser = studentMergeProfile(['instrument_id' => $flute->id]);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect($winner->fresh()?->instrument_id)->toBe($flute->id);
});

test('sharedCandidateDifferences lists same-version candidates that differ in voice part or status, and the merge still keeps the winner candidate', function () {
    $soprano = VoicePart::factory()->create(['name' => 'Soprano I']);
    $alto = VoicePart::factory()->create(['name' => 'Alto II']);
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $differs = Version::factory()->create(['name' => 'All-State Chorus 2027']);
    $matches = Version::factory()->create(['name' => 'Region II Chorus 2027']);
    $winnerCandidate = Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $differs->id, 'voice_part_id' => $soprano->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->registered()->create(['student_id' => $loser->id, 'version_id' => $differs->id, 'voice_part_id' => $alto->id]);
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $matches->id, 'voice_part_id' => $alto->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $matches->id, 'voice_part_id' => $alto->id, 'status' => CandidateStatus::Eligible]);
    $service = app(StudentMergeService::class);

    expect($service->sharedCandidateDifferences($winner, $loser))->toBe([[
        'version_name' => 'All-State Chorus 2027',
        'winner_voice_part' => 'Soprano I',
        'loser_voice_part' => 'Alto II',
        'winner_status' => 'Eligible',
        'loser_status' => 'Registered',
    ]]);

    $service->merge($winner, $loser);

    $winnerCandidate->refresh();

    expect($winnerCandidate->voice_part_id)->toBe($soprano->id)
        ->and($winnerCandidate->status)->toBe(CandidateStatus::Eligible);
});

test('when both are candidates in the same version the loser fee waivers move to the winner, keeping the winner waiver for a shared fee type', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $winnerCandidate = Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);

    CandidateFeeWaiver::factory()->forFeeType(FeeType::Housing)->create(['candidate_id' => $winnerCandidate->id, 'reason' => 'Winner reason']);
    CandidateFeeWaiver::factory()->forFeeType(FeeType::Housing)->create(['candidate_id' => $loserCandidate->id, 'reason' => 'Loser reason']);
    CandidateFeeWaiver::factory()->forFeeType(FeeType::Registration)->create(['candidate_id' => $loserCandidate->id, 'reason' => 'Hardship']);

    $log = app(StudentMergeService::class)->merge($winner, $loser);

    $waivers = CandidateFeeWaiver::where('candidate_id', $winnerCandidate->id)->get()
        ->mapWithKeys(fn (CandidateFeeWaiver $w): array => [(string) $w->getRawOriginal('fee_type') => $w->reason]);

    expect($waivers->all())->toEqual([
        FeeType::Housing->value => 'Winner reason',
        FeeType::Registration->value => 'Hardship',
    ])
        ->and(CandidateFeeWaiver::count())->toBe(2)
        ->and($log)->toContain(['candidate_fee_waivers moved to winner candidate', '1']);
});

test('a loser candidate that moves whole to the winner keeps its fee waiver', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);
    CandidateFeeWaiver::factory()->create(['candidate_id' => $candidate->id]);

    app(StudentMergeService::class)->merge($winner, $loser);

    expect($candidate->refresh()->student_id)->toBe($winner->id)
        ->and($candidate->hasFeeWaived(FeeType::Registration))->toBeTrue();
});
