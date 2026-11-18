<?php

declare(strict_types=1);

use App\Enums\AdjudicationEventType;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\VersionDateType;
use App\Models\AdjudicationEvent;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
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
