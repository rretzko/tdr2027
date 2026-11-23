<?php

declare(strict_types=1);

use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\JudgeMeasuresService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A two-factor (1–9) Room with tolerance 4, two scoring judges and a
 * monitor, and twelve candidates. Judge A gives the same score on both
 * factors; Judge B scores every factor one point worse (higher, since
 * lower is better here), so B's totals are always A's + 2.
 *
 * @return array{version: Version, room: VersionRoom, a: RoomJudge, b: RoomJudge, candidates: list<Candidate>}
 */
function judgeMeasuresScenario(): array
{
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id, 'score_order' => 'asc']);
    $voicePart = VoicePart::factory()->create();
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Solos', 'order_by' => 1, 'tolerance' => 4]);
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 1]);
    $factors = collect(['Tone', 'Pitch'])->map(fn (string $name, int $index): ScoreFactor => ScoreFactor::create([
        'event_id' => $version->event_id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => $name,
        'abbreviation' => strtoupper(substr($name, 0, 2)),
        'best' => 1,
        'worst' => 9,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => $index + 1,
    ]));
    $room->scoreCategories()->attach($category->id);

    $a = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $b = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);
    RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Monitor]);

    $adjudication = app(AdjudicationService::class);
    $candidates = [];
    for ($i = 1; $i <= 12; $i++) {
        $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
        $score = ($i % 7) + 1;
        $adjudication->saveScores($a, $candidate, $version, $factors->mapWithKeys(fn (ScoreFactor $factor): array => [$factor->id => $score])->all());
        $adjudication->saveScores($b, $candidate, $version, $factors->mapWithKeys(fn (ScoreFactor $factor): array => [$factor->id => $score + 1])->all());
        $candidates[] = $candidate;
    }

    return ['version' => $version, 'room' => $room, 'a' => $a, 'b' => $b, 'candidates' => $candidates];
}

test('agreement, tolerance and calibration compare the scoring judges on the same candidates', function () {
    ['version' => $version, 'a' => $a, 'b' => $b] = judgeMeasuresScenario();

    $room = app(JudgeMeasuresService::class)->forVersion($version)[0] ?? [];
    $judges = collect($room['judges'] ?? [])->keyBy('id');

    expect($room['tolerance'] ?? null)->toBe(['value' => 4, 'judgeTotalRange' => 16, 'largestGap' => 2, 'outside' => 0, 'loose' => true])
        ->and($room['pairs'] ?? [])->toHaveCount(1) // the monitor isn't a scoring judge
        ->and($room['pairs'][0] ?? [])->toMatchArray(['candidates' => 12, 'r' => 1.0, 'averageGap' => 2.0, 'largestGap' => 2])
        ->and($judges->get($a->id)['calibration'] ?? null)->toBe(['compared' => 12, 'lean' => 2.0]) // lower totals are better here
        ->and($judges->get($b->id)['calibration'] ?? null)->toBe(['compared' => 12, 'lean' => -2.0]);
});

test('scale use counts values used, the two most-used values and sheets with one score throughout', function () {
    ['version' => $version, 'a' => $a, 'b' => $b] = judgeMeasuresScenario();

    $judges = collect(app(JudgeMeasuresService::class)->forVersion($version)[0]['judges'] ?? [])->keyBy('id');

    // A scored 2,3,…,7,1,2,…,6 on both factors: values 1–7, 2 and 3 most used (2 sheets each of 12 → 8 of 24 scores).
    expect($judges->get($a->id)['scaleUse'] ?? null)->toBe(['valuesUsed' => 7, 'valuesPossible' => 9, 'topTwoShare' => 33, 'sameEveryFactor' => 100])
        ->and($judges->get($b->id)['scaleUse']['sameEveryFactor'] ?? null)->toBe(100);
});

test('a sheet edited well after its first save counts as revised later', function () {
    ['version' => $version, 'a' => $a, 'candidates' => $candidates] = judgeMeasuresScenario();

    Score::where('judge_id', $a->id)->where('candidate_id', $candidates[0]->id)->update(['updated_at' => now()->addMinutes(30)]);

    $judges = collect(app(JudgeMeasuresService::class)->forVersion($version)[0]['judges'] ?? [])->keyBy('id');

    expect($judges->get($a->id)['review']['revised'] ?? null)->toBe(1);
});

test('drift needs enough compared sheets before it reports anything', function () {
    ['version' => $version, 'a' => $a] = judgeMeasuresScenario();

    $judges = collect(app(JudgeMeasuresService::class)->forVersion($version)[0]['judges'] ?? [])->keyBy('id');

    expect($judges->get($a->id)['drift'] ?? null)->toBe(['sheets' => 12, 'firstThird' => null, 'lastThird' => null, 'change' => null]);
});

test('a Version with no Rooms has no judge measures', function () {
    $version = Version::factory()->create(['event_id' => Event::factory()->create()->id]);

    expect(app(JudgeMeasuresService::class)->forVersion($version))->toBe([]);
});
