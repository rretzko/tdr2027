<?php

declare(strict_types=1);

use App\Enums\AdjudicationEventType;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\ScoreOrder;
use App\Enums\VersionDateType;
use App\Models\AdjudicationEvent;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationProgressService;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => actingAs(User::factory()->create()));

afterEach(fn () => Carbon::setTestNow());

/**
 * A Room with a two-factor rubric, $candidateCount Registered candidates and
 * the given judge types.
 *
 * @param  list<JudgeType>  $judgeTypes
 * @return array{room: VersionRoom, factors: list<ScoreFactor>, candidates: list<Candidate>, judges: list<RoomJudge>}
 */
function progressRoomFixture(Version $version, int $candidateCount, array $judgeTypes, string $name = 'Room'): array
{
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => $name, 'tolerance' => null, 'order_by' => 1]);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Scales '.$name, 'order_by' => 1]);
    $factors = collect([1, 2])->map(fn (int $order): ScoreFactor => ScoreFactor::create([
        'event_id' => $version->event_id, 'version_id' => null, 'score_category_id' => $category->id,
        'description' => "F{$order}", 'abbreviation' => "F{$order}", 'best' => 9, 'worst' => 1,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => $order,
    ]))->all();
    $room->scoreCategories()->attach($category->id);

    $candidates = collect(range(1, $candidateCount))
        ->map(fn (): Candidate => Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]))
        ->all();

    $judges = collect($judgeTypes)
        ->map(fn (JudgeType $type): RoomJudge => RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => $type]))
        ->all();

    return ['room' => $room, 'factors' => $factors, 'candidates' => $candidates, 'judges' => $judges];
}

/**
 * Saves a full rubric for each candidate at the given UTC times.
 *
 * @param  array{factors: list<ScoreFactor>}  $fixture
 * @param  list<Candidate>  $candidates
 * @param  list<string>  $times
 */
function progressScoreAt(Version $version, RoomJudge $judge, array $fixture, array $candidates, array $times): void
{
    foreach ($candidates as $index => $candidate) {
        Carbon::setTestNow(Carbon::parse($times[$index]));
        app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [
            $fixture['factors'][0]->id => 5,
            $fixture['factors'][1]->id => 5,
        ]);
    }
    Carbon::setTestNow();
}

test('a finished judge with two sittings reports both and their median in-sitting pace', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 8, [JudgeType::Judge1]);
    $judge = $fixture['judges'][0];

    progressScoreAt($version, $judge, $fixture, $fixture['candidates'], [
        // Sitting 1: gaps 60, 60, 60
        '2026-11-16 14:00:00', '2026-11-16 14:01:00', '2026-11-16 14:02:00', '2026-11-16 14:03:00',
        // Sitting 2 (next day): gaps 90, 90, 120
        '2026-11-17 14:00:00', '2026-11-17 14:01:30', '2026-11-17 14:03:00', '2026-11-17 14:05:00',
    ]);

    $row = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['rooms'][0]['judges'][0];

    expect($row['status'])->toBe('complete')
        ->and($row['completed'])->toBe(8)
        ->and($row['sittings'])->toBe(2)
        ->and($row['paceSeconds'])->toBe(75) // median of 60,60,60,90,90,120
        ->and($row['remainingMinutes'])->toBeNull()
        ->and($row['needsAttention'])->toBeFalse();
});

test('not started, just started and idle judges need attention, and estimates fall back to the typical pace', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 20, [JudgeType::Judge1, JudgeType::Judge2, JudgeType::Judge3, JudgeType::Judge4]);
    [$steady, $sampler, $idle, $absent] = $fixture['judges'];
    $candidates = $fixture['candidates'];

    // Steady: 10 candidates a minute apart, recently — pace 60s, 10 left.
    progressScoreAt($version, $steady, $fixture, array_slice($candidates, 0, 10), array_map(
        fn (int $i): string => Carbon::parse('2026-11-17 15:00:00')->addMinutes($i)->toDateTimeString(),
        range(0, 9),
    ));

    // Sampler: 1 of 20 (under 10%), and nothing in the last day.
    progressScoreAt($version, $sampler, $fixture, [$candidates[0]], ['2026-11-16 12:00:00']);

    // Idle: 5 of 20, last saved 3 days ago.
    progressScoreAt($version, $idle, $fixture, array_slice($candidates, 0, 5), array_map(
        fn (int $i): string => Carbon::parse('2026-11-14 12:00:00')->addMinutes($i)->toDateTimeString(),
        range(0, 4),
    ));

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'));
    $rows = collect($progress['rooms'][0]['judges'])->keyBy(fn (array $judge): int => $judge['judge']->id);

    expect($rows[$steady->id]['status'])->toBe('in_progress')
        ->and($rows[$steady->id]['needsAttention'])->toBeFalse()
        ->and($rows[$steady->id]['paceSeconds'])->toBe(60)
        ->and($rows[$steady->id]['remainingMinutes'])->toBe(10);

    expect($rows[$sampler->id]['status'])->toBe('just_started')
        ->and($rows[$sampler->id]['needsAttention'])->toBeTrue()
        ->and($rows[$sampler->id]['paceIsEstimate'])->toBeTrue();

    expect($rows[$idle->id]['status'])->toBe('in_progress')
        ->and($rows[$idle->id]['idle'])->toBeTrue()
        ->and($rows[$idle->id]['needsAttention'])->toBeTrue();

    // Version typical pace is the median of every in-sitting gap: all 60s here.
    expect($rows[$absent->id]['status'])->toBe('not_started')
        ->and($rows[$absent->id]['needsAttention'])->toBeTrue()
        ->and($rows[$absent->id]['remainingMinutes'])->toBe(20);

    expect($progress['totals'])->toMatchArray([
        'judges' => 4,
        'judgesNotStarted' => 1,
        'judgesNeedingAttention' => 3,
        'expected' => 80,
        'completed' => 16,
        'percent' => 20,
        'paceSeconds' => 60,
    ]);
});

test('monitors and exempt judges are listed but not counted toward progress', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 4, [JudgeType::Judge1, JudgeType::Monitor, JudgeType::Judge2]);
    [$judge, $monitor, $exempt] = $fixture['judges'];
    $exempt->update(['status' => JudgeStatus::Exempt]);

    progressScoreAt($version, $judge, $fixture, $fixture['candidates'], [
        '2026-11-16 14:00:00', '2026-11-16 14:01:00', '2026-11-16 14:02:00', '2026-11-16 14:03:00',
    ]);

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-16 18:00:00'));
    $room = $progress['rooms'][0];
    $rows = collect($room['judges'])->keyBy(fn (array $row): int => $row['judge']->id);

    expect($rows[$monitor->id]['status'])->toBe('not_scoring')
        ->and($rows[$exempt->id]['status'])->toBe('not_scoring')
        ->and($rows[$monitor->id]['needsAttention'])->toBeFalse()
        ->and($room['expected'])->toBe(4)
        ->and($room['percent'])->toBe(100)
        ->and($room['candidatesComplete'])->toBe(4);
});

test('partial rubrics are not complete, and scores from another Room never count', function () {
    $version = Version::factory()->create();
    $scales = progressRoomFixture($version, 2, [JudgeType::Judge1], 'Scales');
    $solos = progressRoomFixture($version, 2, [JudgeType::Judge1], 'Solos');
    $judge = $scales['judges'][0];

    // One full save, one partial.
    progressScoreAt($version, $judge, $scales, [$scales['candidates'][0]], ['2026-11-16 14:00:00']);
    Carbon::setTestNow(Carbon::parse('2026-11-16 14:01:00'));
    app(AdjudicationService::class)->saveScores($judge, $scales['candidates'][1], $version, [$scales['factors'][0]->id => 5]);
    Carbon::setTestNow();

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-16 18:00:00'));
    $rooms = collect($progress['rooms'])->keyBy(fn (array $room): string => $room['room']->name);

    expect($rooms['Scales']['judges'][0])->toMatchArray(['completed' => 1, 'partial' => 1, 'remaining' => 1]);
    expect($rooms['Solos']['completed'])->toBe(0)
        ->and($rooms['Solos']['judges'][0]['status'])->toBe('not_started');
});

test('the window reports open with days left, and daily activity spans it in display time', function () {
    $version = Version::factory()->create();
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication,
        'start_at' => '2026-11-16 00:00:00',
        'end_at' => '2026-11-22 22:00:00',
    ]);
    $fixture = progressRoomFixture($version, 2, [JudgeType::Judge1]);

    // 01:00 UTC on the 16th is the evening of the 15th in New York.
    progressScoreAt($version, $fixture['judges'][0], $fixture, $fixture['candidates'], ['2026-11-16 01:00:00', '2026-11-16 15:00:00']);

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'));

    expect($progress['window']['status'])->toBe('open')
        ->and($progress['window']['daysLeft'])->toBe(6);

    $daily = collect($progress['daily'])->keyBy('date');
    expect($daily->keys()->first())->toBe('2026-11-15')
        ->and($daily->keys()->last())->toBe('2026-11-22')
        ->and($daily['2026-11-15']['saves'])->toBe(1)
        ->and($daily['2026-11-16']['saves'])->toBe(1)
        ->and($daily['2026-11-18']['saves'])->toBe(0);
});

/**
 * An Adjudication window on the Version.
 */
function progressWindow(Version $version, string $start, string $end): void
{
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication,
        'start_at' => $start,
        'end_at' => $end,
    ]);
}

test('a just-started judge who scored recently is working, not in need of a nudge', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 20, [JudgeType::Judge1]);

    progressScoreAt($version, $fixture['judges'][0], $fixture, [$fixture['candidates'][0]], ['2026-11-17 10:00:00']);

    $row = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['rooms'][0]['judges'][0];

    expect($row['status'])->toBe('just_started')
        ->and($row['needsAttention'])->toBeFalse();
});

test('the schedule compares the needed daily rate with the recent one', function () {
    $version = Version::factory()->create();
    progressWindow($version, '2026-11-14 00:00:00', '2026-11-19 00:00:00');
    $fixture = progressRoomFixture($version, 40, [JudgeType::Judge1]);

    // 4 a day on each of four days; the first day's saves fall before the 72-hour rate window.
    $times = [];
    foreach (['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17'] as $day) {
        foreach ([1, 2, 3, 4] as $hour) {
            $times[] = "{$day} 1{$hour}:00:00";
        }
    }
    progressScoreAt($version, $fixture['judges'][0], $fixture, array_slice($fixture['candidates'], 0, 16), $times);

    // Now 2026-11-17 18:00: 24 left with 30 hours (1.25 days) to go, so 20 a day needed.
    // Rate window from 2026-11-14 18:00: 12 completions over 3 days, so 4 a day.
    $schedule = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['schedule'];

    expect($schedule)->toMatchArray([
        'status' => 'behind',
        'remaining' => 24,
        'neededPerDay' => 20,
        'recentPerDay' => 4,
        'rateDays' => 3,
    ])->and($schedule['projectedFinish']->toDateTimeString())->toBe('2026-11-23 18:00:00');
});

test('the schedule is on track or at risk relative to the needed rate, and never measures less than a day', function () {
    $version = Version::factory()->create();
    // Window opened two hours before "now" and closes two days after it.
    progressWindow($version, '2026-11-17 16:00:00', '2026-11-19 18:00:00');
    $fixture = progressRoomFixture($version, 20, [JudgeType::Judge1]);

    // 8 saves in the first two hours: measured over a full day, that is 8 a day.
    progressScoreAt($version, $fixture['judges'][0], $fixture, array_slice($fixture['candidates'], 0, 8), array_map(
        fn (int $i): string => Carbon::parse('2026-11-17 16:30:00')->addMinutes($i * 5)->toDateTimeString(),
        range(0, 7),
    ));

    $service = app(AdjudicationProgressService::class);

    // 12 left over 2 days: 6 a day needed; 8 a day recent.
    expect($service->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['schedule'])
        ->toMatchArray(['status' => 'on_track', 'neededPerDay' => 6, 'recentPerDay' => 8, 'rateDays' => 1]);

    // 12 left over 34 hours: 8.47 a day needed; still measured over one day, so 8 recent (at least 75%).
    expect($service->forVersion($version, Carbon::parse('2026-11-18 08:00:00'))['schedule'])
        ->toMatchArray(['status' => 'at_risk', 'neededPerDay' => 9, 'recentPerDay' => 8]);
});

test('the schedule only projects while the window is open, and reports a finished Version as complete', function () {
    $service = app(AdjudicationProgressService::class);

    $unscheduled = Version::factory()->create();
    progressRoomFixture($unscheduled, 2, [JudgeType::Judge1]);
    expect($service->forVersion($unscheduled, Carbon::parse('2026-11-17 18:00:00'))['schedule']['status'])->toBe('not_applicable');

    $upcoming = Version::factory()->create();
    progressWindow($upcoming, '2026-11-20 00:00:00', '2026-11-25 00:00:00');
    progressRoomFixture($upcoming, 2, [JudgeType::Judge1]);
    expect($service->forVersion($upcoming, Carbon::parse('2026-11-17 18:00:00'))['schedule']['status'])->toBe('not_applicable');

    $done = Version::factory()->create();
    progressWindow($done, '2026-11-14 00:00:00', '2026-11-20 00:00:00');
    $fixture = progressRoomFixture($done, 2, [JudgeType::Judge1]);
    progressScoreAt($done, $fixture['judges'][0], $fixture, $fixture['candidates'], ['2026-11-16 14:00:00', '2026-11-16 14:01:00']);
    expect($service->forVersion($done, Carbon::parse('2026-11-17 18:00:00'))['schedule']['status'])->toBe('complete');
});

test('Rooms are flagged stalled or uneven once the window is open', function () {
    $version = Version::factory()->create();
    progressWindow($version, '2026-11-14 00:00:00', '2026-11-20 00:00:00');
    progressRoomFixture($version, 3, [JudgeType::Judge1, JudgeType::Judge2], 'Stalled');
    $uneven = progressRoomFixture($version, 3, [JudgeType::Judge1, JudgeType::Judge2, JudgeType::Judge3], 'Uneven');
    $moving = progressRoomFixture($version, 3, [JudgeType::Judge1, JudgeType::Monitor], 'Moving');

    progressScoreAt($version, $uneven['judges'][0], $uneven, $uneven['candidates'], ['2026-11-16 14:00:00', '2026-11-16 14:01:00', '2026-11-16 14:02:00']);
    progressScoreAt($version, $moving['judges'][0], $moving, [$moving['candidates'][0]], ['2026-11-16 14:00:00']);

    $rooms = collect(app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['rooms'])
        ->keyBy(fn (array $room): string => $room['room']->name);

    expect($rooms['Stalled'])->toMatchArray(['flag' => 'stalled', 'judgesNotStarted' => 2])
        ->and($rooms['Uneven'])->toMatchArray(['flag' => 'uneven', 'judgesNotStarted' => 2])
        // The monitor does not score, so it never holds a Room up.
        ->and($rooms['Moving'])->toMatchArray(['flag' => null, 'judgesNotStarted' => 0]);
});

test('Rooms are not flagged before the window opens', function () {
    $version = Version::factory()->create();
    progressWindow($version, '2026-11-20 00:00:00', '2026-11-25 00:00:00');
    progressRoomFixture($version, 3, [JudgeType::Judge1]);

    $room = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'))['rooms'][0];

    expect($room['flag'])->toBeNull();
});

test('judges report how much of each recording they heard before saving, from tracked saves only', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 3, [JudgeType::Judge1, JudgeType::Judge2]);
    [$listener, $untracked] = $fixture['judges'];
    [$full, $short, $beforeTracking] = $fixture['candidates'];
    $uploader = User::factory()->create();

    $recordings = collect($fixture['candidates'])->mapWithKeys(fn (Candidate $candidate): array => [$candidate->id => Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Scales Room',
        'uploaded_by' => $uploader->id,
        'approved_at' => now(),
        'approved_by' => $uploader->id,
        'url' => 'recordings/scales.mp3',
        'duration_seconds' => 40,
    ])]);

    $log = function (RoomJudge $judge, Candidate $candidate, AdjudicationEventType $type, string $at, ?int $position = null) use ($version, $recordings): void {
        Carbon::setTestNow(Carbon::parse($at));
        AdjudicationEvent::create([
            'version_id' => $version->id,
            'room_judge_id' => $judge->id,
            'candidate_id' => $candidate->id,
            'recording_id' => $type === AdjudicationEventType::CandidateOpened ? null : $recordings[$candidate->id]->id,
            'event' => $type,
            'position_seconds' => $position,
        ]);
        Carbon::setTestNow();
    };

    // Scored before tracking began: no open event, so not measured.
    progressScoreAt($version, $listener, $fixture, [$beforeTracking], ['2026-11-16 14:00:00']);

    // Heard all 40s, then saved.
    $log($listener, $full, AdjudicationEventType::CandidateOpened, '2026-11-17 14:00:00');
    $log($listener, $full, AdjudicationEventType::RecordingPlayed, '2026-11-17 14:00:01', 0);
    $log($listener, $full, AdjudicationEventType::RecordingEnded, '2026-11-17 14:00:41', 40);
    progressScoreAt($version, $listener, $fixture, [$full], ['2026-11-17 14:00:45']);

    // Saved 20s in and moved on: no pause or end event.
    $log($listener, $short, AdjudicationEventType::CandidateOpened, '2026-11-17 14:01:00');
    $log($listener, $short, AdjudicationEventType::RecordingPlayed, '2026-11-17 14:01:01', 0);
    progressScoreAt($version, $listener, $fixture, [$short], ['2026-11-17 14:01:21']);

    // The other judge scored with no tracking at all.
    progressScoreAt($version, $untracked, $fixture, [$full], ['2026-11-17 15:00:00']);

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'));
    $rows = collect($progress['rooms'][0]['judges'])->keyBy(fn (array $row): int => $row['judge']->id);

    expect($progress['listeningTracked'])->toBeTrue()
        ->and($rows[$listener->id]['listening'])->toBe(['measured' => 2, 'heardPercent' => 75, 'short' => 1])
        ->and($rows[$untracked->id]['listening'])->toBe(['measured' => 0, 'heardPercent' => null, 'short' => 0]);
});

test('listening is not tracked for a Version with no playback events', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, 1, [JudgeType::Judge1]);
    progressScoreAt($version, $fixture['judges'][0], $fixture, $fixture['candidates'], ['2026-11-16 14:00:00']);

    $progress = app(AdjudicationProgressService::class)->forVersion($version, Carbon::parse('2026-11-17 18:00:00'));

    expect($progress['listeningTracked'])->toBeFalse()
        ->and($progress['rooms'][0]['judges'][0]['listening']['measured'])->toBe(0);
});

/**
 * Judge 1 teaches candidate 0 and teaches at candidate 1's school; candidate 2
 * is unrelated. Judge 2 has no Teacher record.
 *
 * @return array{fixture: array{room: VersionRoom, factors: list<ScoreFactor>, candidates: list<Candidate>, judges: list<RoomJudge>}, school: School}
 */
function ownStudentRoomFixture(Version $version, bool $verified = true): array
{
    $fixture = progressRoomFixture($version, 3, [JudgeType::Judge1, JudgeType::Judge2]);
    $teacher = Teacher::factory()->create(['user_id' => $fixture['judges'][0]->user_id]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => $verified ? now() : null]);

    [$own, $sameSchool] = $fixture['candidates'];
    $own->update(['teacher_id' => $teacher->id]);
    $sameSchool->update(['school_id' => $school->id]);

    return ['fixture' => $fixture, 'school' => $school];
}

/**
 * @param  array{factors: list<ScoreFactor>}  $fixture
 */
function ownStudentScore(Version $version, RoomJudge $judge, array $fixture, Candidate $candidate, int $first, int $second): void
{
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [
        $fixture['factors'][0]->id => $first,
        $fixture['factors'][1]->id => $second,
    ]);
}

test('judges with their own or their school\'s students are flagged, with their lean against co-judges', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    ['fixture' => $fixture] = ownStudentRoomFixture($version);
    [$judge, $coJudge] = $fixture['judges'];
    [$own, $sameSchool, $other] = $fixture['candidates'];

    // Lower is better: 6 vs the co-judge's 10 is 4 points more favorable; 10 vs 10 is level.
    ownStudentScore($version, $judge, $fixture, $own, 3, 3);
    ownStudentScore($version, $coJudge, $fixture, $own, 5, 5);
    ownStudentScore($version, $judge, $fixture, $sameSchool, 5, 5);
    ownStudentScore($version, $coJudge, $fixture, $sameSchool, 5, 5);
    // Unrelated candidates never feed the lean.
    ownStudentScore($version, $judge, $fixture, $other, 1, 1);
    ownStudentScore($version, $coJudge, $fixture, $other, 9, 9);

    $rows = collect(app(AdjudicationProgressService::class)->forVersion($version)['rooms'][0]['judges'])
        ->keyBy(fn (array $row): int => $row['judge']->id);

    expect($rows[$judge->id]['ownStudents'])->toBe(['count' => 2, 'ownTeacher' => 1, 'sameSchool' => 1, 'compared' => 2, 'lean' => 2.0])
        ->and($rows[$coJudge->id]['ownStudents'])->toBe(['count' => 0, 'ownTeacher' => 0, 'sameSchool' => 0, 'compared' => 0, 'lean' => null]);
});

test('lean follows the Version\'s score order and needs a co-judge\'s full rubric', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Desc]);
    ['fixture' => $fixture] = ownStudentRoomFixture($version);
    [$judge, $coJudge] = $fixture['judges'];
    [$own, $sameSchool] = $fixture['candidates'];

    // Higher is better: 6 vs 10 is 4 points harsher.
    ownStudentScore($version, $judge, $fixture, $own, 3, 3);
    ownStudentScore($version, $coJudge, $fixture, $own, 5, 5);
    // The co-judge hasn't scored this one, so there's nothing to compare.
    ownStudentScore($version, $judge, $fixture, $sameSchool, 9, 9);

    $own = app(AdjudicationProgressService::class)->forVersion($version)['rooms'][0]['judges'][0]['ownStudents'];

    expect($own['compared'])->toBe(1)
        ->and($own['lean'])->toBe(-4.0);
});

test('a school the judge is not verified at does not make its students the judge\'s own', function () {
    $version = Version::factory()->create();
    ['fixture' => $fixture] = ownStudentRoomFixture($version, verified: false);

    $own = app(AdjudicationProgressService::class)->forVersion($version)['rooms'][0]['judges'][0]['ownStudents'];

    expect($own['count'])->toBe(1)
        ->and($own['ownTeacher'])->toBe(1)
        ->and($own['sameSchool'])->toBe(0)
        ->and($own['lean'])->toBeNull();
});

test('judges in another Room hearing the same candidate are not co-judges', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    ['fixture' => $fixture] = ownStudentRoomFixture($version);
    [$judge, $coJudge] = $fixture['judges'];
    [$own] = $fixture['candidates'];

    // A second Room hears the same voice part on its own rubric.
    $other = progressRoomFixture($version, 0, [JudgeType::Judge1], 'Solos');
    $other['room']->voiceParts()->attach($own->voice_part_id);

    ownStudentScore($version, $judge, $fixture, $own, 3, 3);
    ownStudentScore($version, $coJudge, $fixture, $own, 4, 4);
    ownStudentScore($version, $other['judges'][0], $other, $own, 9, 9);

    $rows = collect(app(AdjudicationProgressService::class)->forVersion($version)['rooms'])
        ->firstWhere(fn (array $room): bool => $room['room']->is($fixture['room']))['judges'];

    expect($rows[0]['ownStudents']['compared'])->toBe(1)
        ->and($rows[0]['ownStudents']['lean'])->toBe(2.0);
});

test('halo is the average correlation between a judge\'s factor scores, once they have enough full sheets', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, AdjudicationProgressService::HALO_MIN_SHEETS, [JudgeType::Judge1, JudgeType::Judge2, JudgeType::Judge3, JudgeType::Judge4]);
    [$impression, $separate, $flat, $few] = $fixture['judges'];

    $first = [1, 2, 3, 4, 5, 6, 7, 8, 9, 5];
    $second = [5, 9, 1, 8, 2, 7, 3, 6, 4, 5];
    foreach ($fixture['candidates'] as $i => $candidate) {
        ownStudentScore($version, $impression, $fixture, $candidate, $first[$i], $first[$i]);
        ownStudentScore($version, $separate, $fixture, $candidate, $first[$i], $second[$i]);
        ownStudentScore($version, $flat, $fixture, $candidate, 5, 5);
        if ($i < AdjudicationProgressService::HALO_MIN_SHEETS - 1) {
            ownStudentScore($version, $few, $fixture, $candidate, $first[$i], $first[$i]);
        }
    }

    $rows = collect(app(AdjudicationProgressService::class)->forVersion($version)['rooms'][0]['judges'])
        ->keyBy(fn (array $row): int => $row['judge']->id);

    expect($rows[$impression->id]['halo'])->toBe(['sheets' => 10, 'r' => 1.0])
        ->and($rows[$separate->id]['halo']['r'])->toBeLessThan(0.0)
        ->and($rows[$flat->id]['halo'])->toBe(['sheets' => 10, 'r' => null]) // never varies
        ->and($rows[$few->id]['halo'])->toBe(['sheets' => 9, 'r' => null]);
});

test('halo leaves out factor pairs from different score categories', function () {
    $version = Version::factory()->create();
    $fixture = progressRoomFixture($version, AdjudicationProgressService::HALO_MIN_SHEETS, [JudgeType::Judge1]);
    $judge = $fixture['judges'][0];

    $solo = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $soloFactors = collect([1, 2])->map(fn (int $order): ScoreFactor => ScoreFactor::create([
        'event_id' => $version->event_id, 'version_id' => null, 'score_category_id' => $solo->id,
        'description' => "S{$order}", 'abbreviation' => "S{$order}", 'best' => 9, 'worst' => 1,
        'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => $order,
    ]))->all();
    $fixture['room']->scoreCategories()->attach($solo->id);

    // Each category's two factors agree exactly; the categories don't track each other.
    $scales = [1, 2, 3, 4, 5, 6, 7, 8, 9, 5];
    $solos = [5, 9, 1, 8, 2, 7, 3, 6, 4, 5];
    foreach ($fixture['candidates'] as $i => $candidate) {
        app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [
            $fixture['factors'][0]->id => $scales[$i],
            $fixture['factors'][1]->id => $scales[$i],
            $soloFactors[0]->id => $solos[$i],
            $soloFactors[1]->id => $solos[$i],
        ]);
    }

    $halo = app(AdjudicationProgressService::class)->forVersion($version)['rooms'][0]['judges'][0]['halo'];

    expect($halo)->toBe(['sheets' => 10, 'r' => 1.0]);
});

/**
 * A Scales Room and a Solos Room hearing the same candidates, one judge each.
 *
 * @return array{scales: array{room: VersionRoom, factors: list<ScoreFactor>, candidates: list<Candidate>, judges: list<RoomJudge>}, solos: array{room: VersionRoom, factors: list<ScoreFactor>, candidates: list<Candidate>, judges: list<RoomJudge>}}
 */
function agreementRoomsFixture(Version $version, int $candidateCount): array
{
    $scales = progressRoomFixture($version, $candidateCount, [JudgeType::Judge1], 'Scales');
    $solos = progressRoomFixture($version, 0, [JudgeType::Judge1], 'Solos');
    $solos['room']->voiceParts()->attach($scales['candidates'][0]->voice_part_id);

    return ['scales' => $scales, 'solos' => $solos];
}

test('Rooms hearing the same candidates in the same order agree completely', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    ['scales' => $scales, 'solos' => $solos] = agreementRoomsFixture($version, 10);

    foreach ($scales['candidates'] as $i => $candidate) {
        ownStudentScore($version, $scales['judges'][0], $scales, $candidate, min($i + 1, 9), $i === 9 ? 2 : 1);
        ownStudentScore($version, $solos['judges'][0], $solos, $candidate, min($i + 1, 9), $i === 9 ? 2 : 1);
    }

    $agreement = app(AdjudicationProgressService::class)->forVersion($version)['agreement'];

    expect($agreement['candidates'])->toBe(10)
        ->and($agreement['r'])->toBe(1.0)
        ->and($agreement['gaps'])->toBe([]);
});

test('candidates whose standing differs widely between Rooms are listed, widest first', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    ['scales' => $scales, 'solos' => $solos] = agreementRoomsFixture($version, 10);
    $candidates = $scales['candidates'];

    // Lower is better: Scales ranks candidate 0 first, Solos ranks it last.
    foreach ($candidates as $i => $candidate) {
        ownStudentScore($version, $scales['judges'][0], $scales, $candidate, min($i + 1, 9), $i === 9 ? 2 : 1);
        $reverse = 9 - $i;
        ownStudentScore($version, $solos['judges'][0], $solos, $candidate, min($reverse + 1, 9), $reverse === 9 ? 2 : 1);
    }

    $agreement = app(AdjudicationProgressService::class)->forVersion($version)['agreement'];

    expect($agreement['r'])->toBe(-1.0)
        // Percentiles 100, 89, 78, 67, 56, 44, ...: gaps of 100, 78, 56 at each end.
        ->and(array_map(fn (array $gap): int => $gap['gap'], $agreement['gaps']))->toBe([100, 100, 78, 78, 56, 56])
        ->and(collect($agreement['gaps'])->take(2)->map(fn (array $gap): int => $gap['candidate']->id)->sort()->values()->all())
        ->toBe(collect([$candidates[0]->id, $candidates[9]->id])->sort()->values()->all())
        ->and(collect($agreement['gaps'])->firstWhere(fn (array $gap): bool => $gap['candidate']->is($candidates[0]))['rooms'])->toBe([
            ['name' => 'Scales', 'percentile' => 100, 'judges' => 1, 'of' => 1],
            ['name' => 'Solos', 'percentile' => 0, 'judges' => 1, 'of' => 1],
        ]);
});

test('agreement needs enough shared candidates, ties share a percentile, and higher-is-better Versions rank the other way', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Desc]);
    ['scales' => $scales, 'solos' => $solos] = agreementRoomsFixture($version, 3);
    [$high, $tied, $alsoTied] = $scales['candidates'];

    ownStudentScore($version, $scales['judges'][0], $scales, $high, 9, 9);
    ownStudentScore($version, $scales['judges'][0], $scales, $tied, 1, 1);
    ownStudentScore($version, $scales['judges'][0], $scales, $alsoTied, 1, 1);
    // Solos: the reverse, so the top Scales candidate is last here.
    ownStudentScore($version, $solos['judges'][0], $solos, $high, 1, 1);
    ownStudentScore($version, $solos['judges'][0], $solos, $tied, 9, 9);
    ownStudentScore($version, $solos['judges'][0], $solos, $alsoTied, 5, 5);

    $agreement = app(AdjudicationProgressService::class)->forVersion($version)['agreement'];
    $rooms = collect($agreement['gaps'])->mapWithKeys(fn (array $gap): array => [$gap['candidate']->id => $gap['rooms']]);

    expect($agreement['candidates'])->toBe(3)
        ->and($agreement['r'])->toBeNull()
        ->and($rooms[$high->id])->toBe([['name' => 'Scales', 'percentile' => 100, 'judges' => 1, 'of' => 1], ['name' => 'Solos', 'percentile' => 0, 'judges' => 1, 'of' => 1]])
        // Tied for last in Scales: both at the average of positions 1 and 2.
        ->and($rooms[$tied->id][0]['percentile'])->toBe(25)
        ->and($rooms[$tied->id][1]['percentile'])->toBe(100);
});

test('the Adjudication tab shows the Rooms\' agreement and lists candidates far apart', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    ['scales' => $scales, 'solos' => $solos] = agreementRoomsFixture($version, 10);
    $candidates = $scales['candidates'];
    $candidates[0]->update(['program_name' => 'Robin Leaning']);

    foreach ($candidates as $i => $candidate) {
        ownStudentScore($version, $scales['judges'][0], $scales, $candidate, min($i + 1, 9), $i === 9 ? 2 : 1);
        $reverse = 9 - $i;
        ownStudentScore($version, $solos['judges'][0], $solos, $candidate, min($reverse + 1, 9), $reverse === 9 ? 2 : 1);
    }

    $html = view('livewire.events.version-scorecard-adjudication', [
        'progress' => app(AdjudicationProgressService::class)->forVersion($version),
    ])->render();

    expect($html)->toContain('Scales vs Solo agreement')
        ->toContain('-1.00')
        ->toContain('loosely alike')
        ->toContain('Robin Leaning');
});

test('each standing on a gap row says how many of the Room judges it rests on', function () {
    $version = Version::factory()->create(['score_order' => ScoreOrder::Asc]);
    $scales = progressRoomFixture($version, 10, [JudgeType::Judge1, JudgeType::Judge2], 'Scales');
    $solos = progressRoomFixture($version, 0, [JudgeType::Judge1, JudgeType::Judge2, JudgeType::Judge3], 'Solos');
    $solos['room']->voiceParts()->attach($scales['candidates'][0]->voice_part_id);
    $candidates = $scales['candidates'];

    // Scales: both judges score everyone. Solos: only the first judge has
    // started, in the reverse order, so candidate 0 rests on 1 of 3 there.
    foreach ($candidates as $i => $candidate) {
        foreach ($scales['judges'] as $judge) {
            ownStudentScore($version, $judge, $scales, $candidate, min($i + 1, 9), $i === 9 ? 2 : 1);
        }
        $reverse = 9 - $i;
        ownStudentScore($version, $solos['judges'][0], $solos, $candidate, min($reverse + 1, 9), $reverse === 9 ? 2 : 1);
    }

    $progress = app(AdjudicationProgressService::class)->forVersion($version);
    $gap = collect($progress['agreement']['gaps'])->firstWhere(fn (array $gap): bool => $gap['candidate']->is($candidates[0]));

    expect($gap['rooms'])->toBe([
        ['name' => 'Scales', 'percentile' => 100, 'judges' => 2, 'of' => 2],
        ['name' => 'Solos', 'percentile' => 0, 'judges' => 1, 'of' => 3],
    ]);

    $html = view('livewire.events.version-scorecard-adjudication', ['progress' => $progress])->render();

    expect($html)->toContain('2 of 2 judges')
        ->toContain('1 of 3 judges')
        ->toContain('may move as the rest finish scoring');
});
