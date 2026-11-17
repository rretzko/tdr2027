<?php

declare(strict_types=1);

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\VersionDateType;
use App\Models\Candidate;
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

    // Sampler: 1 of 20 (under 10%).
    progressScoreAt($version, $sampler, $fixture, [$candidates[0]], ['2026-11-17 16:00:00']);

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
