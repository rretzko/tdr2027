<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JudgeSurvey\JudgeAgain;
use App\Enums\JudgeSurvey\JudgeCheckpoint;
use App\Enums\JudgeSurvey\JudgeDeviceType;
use App\Enums\JudgeSurvey\JudgeFocus;
use App\Enums\JudgeSurvey\JudgeObstacle;
use App\Enums\JudgeSurvey\ListeningDevice;
use App\Models\JudgeSurveyResponse;
use App\Models\Version;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * The judge check-in answers for one Version, in aggregate only, for the
 * Scorecard's Adjudication tab and the end-of-Version analytics report.
 *
 * Anonymity: nothing here is keyed by judge. Answer breakdowns and
 * comments stay hidden until MIN_RESPONDENTS judges have answered, because
 * with one or two respondents an Event Manager could tell who said what.
 * Comments are in a scrambled but stable order (a hash of the row id), so
 * their order doesn't follow when they were sent.
 */
final class JudgeSurveySummaryService
{
    public const MIN_RESPONDENTS = 3;

    /**
     * @return array{
     *     enabled: bool,
     *     shown: int,
     *     answered: int,
     *     dismissed: int,
     *     responseRate: ?int,
     *     respondents: int,
     *     visible: bool,
     *     checkpoints: list<array{checkpoint: JudgeCheckpoint, shown: int, answered: int, focus: array<string, int>, focusAnswers: int}>,
     *     obstacles: array{respondents: int, counts: array<string, int>},
     *     listening: array{respondents: int, counts: array<string, int>},
     *     devices: array{judges: int, counts: array<string, int>},
     *     overall: array{respondents: int, average: ?float, counts: array<int, int>},
     *     judgeAgain: array{respondents: int, counts: array<string, int>},
     *     comments: list<array{checkpoint: JudgeCheckpoint, text: string}>,
     * }|null
     */
    public function forVersion(Version $version): ?array
    {
        $rows = $this->rows($version);

        if ($rows->isEmpty() && ! $version->judge_survey) {
            return null;
        }

        $answered = $rows->filter(fn (array $row): bool => $row['answered']);
        $respondents = $answered->pluck('judge')->unique()->count();

        $comments = [];
        foreach ($answered->sortBy(fn (array $row): int => crc32($version->id.'-'.$row['id'])) as $row) {
            if ($row['comment'] !== null) {
                $comments[] = ['checkpoint' => $row['checkpoint'], 'text' => $row['comment']];
            }
        }

        return [
            'enabled' => (bool) $version->judge_survey,
            'shown' => $rows->count(),
            'answered' => $answered->count(),
            'dismissed' => $rows->filter(fn (array $row): bool => $row['dismissed'])->count(),
            'responseRate' => $rows->isEmpty() ? null : (int) round($answered->count() / $rows->count() * 100),
            'respondents' => $respondents,
            'visible' => $respondents >= self::MIN_RESPONDENTS,
            'checkpoints' => $this->checkpoints($rows),
            'obstacles' => $this->obstacles($answered),
            'listening' => $this->tally($answered->pluck('listening')->filter(), ListeningDevice::cases()),
            'devices' => $this->devices($rows),
            'overall' => $this->overall($answered),
            'judgeAgain' => $this->tally($answered->pluck('judgeAgain')->filter(), JudgeAgain::cases()),
            'comments' => $comments,
        ];
    }

    /**
     * The responses as plain values. Read from the raw columns: Larastan
     * types method-declared enum casts as strings.
     *
     * @return Collection<int, covariant array{id: int, judge: int, checkpoint: JudgeCheckpoint, answered: bool, dismissed: bool, device: ?JudgeDeviceType, focus: ?JudgeFocus, obstacles: list<string>, listening: ?ListeningDevice, rating: ?int, judgeAgain: ?JudgeAgain, comment: ?string}>
     */
    private function rows(Version $version): Collection
    {
        $rows = [];

        foreach (JudgeSurveyResponse::where('version_id', $version->id)->get() as $response) {
            $raw = function (string $column) use ($response): ?string {
                $value = $response->getRawOriginal($column);

                return $value !== null ? (string) $value : null;
            };
            $obstacles = json_decode($raw('obstacles') ?? '[]', true);
            $rating = $raw('overall_rating');

            $rows[] = [
                'id' => (int) $response->id,
                'judge' => (int) $response->room_judge_id,
                'checkpoint' => JudgeCheckpoint::from((string) $raw('checkpoint')),
                'answered' => $raw('submitted_at') !== null,
                'dismissed' => $raw('dismissed_at') !== null,
                'device' => JudgeDeviceType::tryFrom($raw('device_type') ?? ''),
                'focus' => JudgeFocus::tryFrom($raw('focus') ?? ''),
                'obstacles' => is_array($obstacles) ? array_values(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $obstacles)) : [],
                'listening' => ListeningDevice::tryFrom($raw('listening_device') ?? ''),
                'rating' => $rating !== null ? (int) $rating : null,
                'judgeAgain' => JudgeAgain::tryFrom($raw('judge_again') ?? ''),
                'comment' => $raw('comment'),
            ];
        }

        return collect($rows);
    }

    /**
     * Focus at each checkpoint, in order: the fatigue trend.
     *
     * @param  Collection<int, covariant array{id: int, judge: int, checkpoint: JudgeCheckpoint, answered: bool, dismissed: bool, device: ?JudgeDeviceType, focus: ?JudgeFocus, obstacles: list<string>, listening: ?ListeningDevice, rating: ?int, judgeAgain: ?JudgeAgain, comment: ?string}>  $rows
     * @return list<array{checkpoint: JudgeCheckpoint, shown: int, answered: int, focus: array<string, int>, focusAnswers: int}>
     */
    private function checkpoints(Collection $rows): array
    {
        $checkpoints = [];

        foreach (JudgeCheckpoint::cases() as $checkpoint) {
            $atCheckpoint = $rows->filter(fn (array $row): bool => $row['checkpoint'] === $checkpoint);
            $answered = $atCheckpoint->filter(fn (array $row): bool => $row['answered']);
            $focus = $this->tally($answered->pluck('focus')->filter(), JudgeFocus::cases());

            $checkpoints[] = [
                'checkpoint' => $checkpoint,
                'shown' => $atCheckpoint->count(),
                'answered' => $answered->count(),
                'focus' => $focus['counts'],
                'focusAnswers' => $focus['respondents'],
            ];
        }

        return $checkpoints;
    }

    /**
     * @param  Collection<int, covariant array{id: int, judge: int, checkpoint: JudgeCheckpoint, answered: bool, dismissed: bool, device: ?JudgeDeviceType, focus: ?JudgeFocus, obstacles: list<string>, listening: ?ListeningDevice, rating: ?int, judgeAgain: ?JudgeAgain, comment: ?string}>  $answered
     * @return array{respondents: int, counts: array<string, int>}
     */
    private function obstacles(Collection $answered): array
    {
        $withObstacles = $answered->filter(fn (array $row): bool => $row['obstacles'] !== []);
        $counts = [];

        foreach (JudgeObstacle::cases() as $obstacle) {
            $counts[$obstacle->value] = $withObstacles->filter(fn (array $row): bool => in_array($obstacle->value, $row['obstacles'], true))->count();
        }

        return ['respondents' => $withObstacles->count(), 'counts' => $counts];
    }

    /**
     * Each judge counted once, on the screen they used most across their
     * checkpoints (dismissed ones included: the device is detected, not asked).
     *
     * @param  Collection<int, covariant array{id: int, judge: int, checkpoint: JudgeCheckpoint, answered: bool, dismissed: bool, device: ?JudgeDeviceType, focus: ?JudgeFocus, obstacles: list<string>, listening: ?ListeningDevice, rating: ?int, judgeAgain: ?JudgeAgain, comment: ?string}>  $rows
     * @return array{judges: int, counts: array<string, int>}
     */
    private function devices(Collection $rows): array
    {
        /** @var array<int, array<string, int>> $usage judge => device value => checkpoints */
        $usage = [];

        foreach ($rows as $row) {
            if ($row['device'] !== null) {
                $usage[$row['judge']][$row['device']->value] = ($usage[$row['judge']][$row['device']->value] ?? 0) + 1;
            }
        }

        $perJudge = [];

        foreach ($usage as $counts) {
            arsort($counts);
            $device = JudgeDeviceType::tryFrom((string) array_key_first($counts));

            if ($device !== null) {
                $perJudge[] = $device;
            }
        }

        $tally = $this->tally(collect($perJudge), JudgeDeviceType::cases());

        return ['judges' => $tally['respondents'], 'counts' => $tally['counts']];
    }

    /**
     * @param  Collection<int, covariant array{id: int, judge: int, checkpoint: JudgeCheckpoint, answered: bool, dismissed: bool, device: ?JudgeDeviceType, focus: ?JudgeFocus, obstacles: list<string>, listening: ?ListeningDevice, rating: ?int, judgeAgain: ?JudgeAgain, comment: ?string}>  $answered
     * @return array{respondents: int, average: ?float, counts: array<int, int>}
     */
    private function overall(Collection $answered): array
    {
        $ratings = [];

        foreach ($answered as $row) {
            if ($row['rating'] !== null) {
                $ratings[] = $row['rating'];
            }
        }

        $counts = [];

        foreach (range(1, 5) as $rating) {
            $counts[$rating] = count(array_filter($ratings, fn (int $r): bool => $r === $rating));
        }

        return [
            'respondents' => count($ratings),
            'average' => $ratings === [] ? null : round(array_sum($ratings) / count($ratings), 1),
            'counts' => $counts,
        ];
    }

    /**
     * Counts of each case, in the enum's own order, zero-filled.
     *
     * @param  Collection<array-key, mixed>  $values
     * @param  list<BackedEnum>  $cases
     * @return array{respondents: int, counts: array<string, int>}
     */
    private function tally(Collection $values, array $cases): array
    {
        $counts = [];

        foreach ($cases as $case) {
            $counts[(string) $case->value] = $values->filter(fn (mixed $value): bool => $value === $case)->count();
        }

        return ['respondents' => $values->count(), 'counts' => $counts];
    }
}
