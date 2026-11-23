<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdjudicationEventType;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\ScoreOrder;
use App\Models\AdjudicationEvent;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Version;
use App\Models\VersionRoom;
use Illuminate\Support\Collection;

/**
 * How each Room's judges scored relative to one another
 * (docs/plans/version-analytics-report.md § Adjudication): judge-pair
 * agreement and tolerance, calibration (harsh or lenient against co-judges),
 * scale use, drift across a judge's roster, and review passes. Information
 * for Event Managers planning the next cycle, not a verdict on any judge.
 *
 * Every measure compares a judge with their co-judges on the same
 * candidates, never with raw totals: the roster runs in voice-part order,
 * so raw totals change as the voice parts change.
 *
 * Only full sheets count — a judge's scores for a candidate covering every
 * factor in the Room's rubric. Totals are plain sums of factor scores, the
 * same as AdjudicationService::candidateTolerances() and the Scorecard.
 *
 * No score history is kept, so review passes say which sheets were edited
 * later and how many recordings the judge replayed, not how far scores moved.
 */
final class JudgeMeasuresService
{
    /** Fewest shared candidates before a pair correlation means anything. */
    public const MIN_PAIR_CANDIDATES = 10;

    /** Fewest compared sheets before thirds of a roster are worth comparing. */
    public const MIN_DRIFT_SHEETS = 15;

    /** An edit this long after the first save counts as a later review, not a correction while scoring. */
    public const REVIEW_AFTER_SECONDS = 600;

    /** A tolerance this large a share of a judge's possible total range is treated as unlikely to ever trigger. */
    public const LOOSE_TOLERANCE_RATIO = 0.25;

    public function __construct(private readonly AdjudicationService $adjudication) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forVersion(Version $version): array
    {
        $rooms = VersionRoom::where('version_id', $version->id)
            ->with(['voiceParts', 'roomJudges.user', 'scoreCategories.scoreFactors'])
            ->orderBy('order_by')
            ->orderBy('id')
            ->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        $higherIsBetter = $version->getRawOriginal('score_order') === ScoreOrder::Desc->value;

        // judge id => candidate id => ['factors' => factor id => score, 'first' => ts, 'last' => ts]
        $sheets = [];
        foreach (Score::where('version_id', $version->id)->toBase()->get(['judge_id', 'candidate_id', 'score_factor_id', 'score', 'created_at', 'updated_at']) as $row) {
            $judgeId = (int) $row->judge_id;
            $candidateId = (int) $row->candidate_id;
            $sheet = $sheets[$judgeId][$candidateId] ?? ['factors' => [], 'first' => PHP_INT_MAX, 'last' => 0];
            $sheet['factors'][(int) $row->score_factor_id] = (int) $row->score;
            $sheet['first'] = min($sheet['first'], (int) strtotime((string) $row->created_at));
            $sheet['last'] = max($sheet['last'], (int) strtotime((string) $row->updated_at));
            $sheets[$judgeId][$candidateId] = $sheet;
        }

        $reopened = $this->reopenedAfterSave($version, $sheets);

        return $rooms
            ->map(fn (VersionRoom $room): ?array => $this->room($room, $sheets, $reopened, $higherIsBetter))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, array{factors: array<int, int>, first: int, last: int}>>  $sheets
     * @param  array<int, array{candidates: int, plays: int}>  $reopened
     * @return ?array<string, mixed>
     */
    private function room(VersionRoom $room, array $sheets, array $reopened, bool $higherIsBetter): ?array
    {
        $factors = $room->scoreCategories->flatMap(fn (ScoreCategory $category): Collection => $category->scoreFactors);
        $factorIds = $factors->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($factorIds === []) {
            return null;
        }

        $judges = $room->roomJudges
            ->filter(fn (RoomJudge $judge): bool => JudgeType::from($judge->getRawOriginal('judge_type')) !== JudgeType::Monitor
                && JudgeStatus::from($judge->getRawOriginal('status')) !== JudgeStatus::Exempt)
            ->values();

        $candidateIds = $this->adjudication->candidatesForRoom($room)->pluck('id')->flip();

        // judge id => candidate id => total, full sheets only
        $totals = [];
        foreach ($judges as $judge) {
            foreach ($sheets[$judge->id] ?? [] as $candidateId => $sheet) {
                if ($candidateIds->has($candidateId) && array_diff($factorIds, array_keys($sheet['factors'])) === []) {
                    $totals[$judge->id][$candidateId] = array_sum(array_intersect_key($sheet['factors'], array_flip($factorIds)));
                }
            }
        }

        if ($totals === []) {
            return null;
        }

        $judgeRange = $this->judgeTotalRange($factors);
        $tolerance = $room->tolerance === null ? null : (int) $room->tolerance;
        $gaps = $this->candidateGaps($totals);

        return [
            'room' => ['id' => $room->id, 'name' => (string) $room->name],
            'tolerance' => [
                'value' => $tolerance,
                'judgeTotalRange' => $judgeRange,
                'largestGap' => $gaps === [] ? null : max($gaps),
                'outside' => $tolerance === null ? 0 : count(array_filter($gaps, fn (int $gap): bool => $gap > $tolerance)),
                'loose' => $tolerance !== null && $judgeRange > 0 && $tolerance >= $judgeRange * self::LOOSE_TOLERANCE_RATIO,
            ],
            'pairs' => $this->pairs($judges, $totals),
            'judges' => $judges
                ->filter(fn (RoomJudge $judge): bool => isset($totals[$judge->id]))
                ->map(fn (RoomJudge $judge): array => $this->judge($judge, $totals, $sheets[$judge->id] ?? [], $factors, $candidateIds->all(), $reopened[$judge->id] ?? ['candidates' => 0, 'plays' => 0], $higherIsBetter))
                ->values()
                ->all(),
        ];
    }

    /**
     * The spread between a judge's best and worst possible totals in this
     * Room: the sum of each factor's own best-to-worst span.
     *
     * @param  Collection<int, ScoreFactor>  $factors
     */
    private function judgeTotalRange(Collection $factors): int
    {
        return (int) $factors->sum(fn (ScoreFactor $factor): int => abs((int) $factor->best - (int) $factor->worst));
    }

    /**
     * Each candidate's widest gap between two judges' totals — the measure
     * a Room's tolerance is checked against.
     *
     * @param  array<int, array<int, int>>  $totals  judge id => candidate id => total
     * @return array<int, int> candidate id => gap
     */
    private function candidateGaps(array $totals): array
    {
        $byCandidate = [];
        foreach ($totals as $candidateTotals) {
            foreach ($candidateTotals as $candidateId => $total) {
                $byCandidate[$candidateId][] = $total;
            }
        }

        $gaps = [];
        foreach ($byCandidate as $candidateId => $values) {
            if (count($values) >= 2) {
                $gaps[$candidateId] = max($values) - min($values);
            }
        }

        return $gaps;
    }

    /**
     * Agreement for every pair of scoring judges, over the candidates both
     * fully scored.
     *
     * @param  Collection<int, RoomJudge>  $judges
     * @param  array<int, array<int, int>>  $totals
     * @return list<array{judges: list<string>, candidates: int, r: ?float, averageGap: ?float, largestGap: ?int}>
     */
    private function pairs(Collection $judges, array $totals): array
    {
        $pairs = [];
        $list = $judges->filter(fn (RoomJudge $judge): bool => isset($totals[$judge->id]))->values();

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $shared = array_keys(array_intersect_key($totals[$a->id], $totals[$b->id]));
                $xs = array_map(fn (int $id): int => $totals[$a->id][$id], $shared);
                $ys = array_map(fn (int $id): int => $totals[$b->id][$id], $shared);
                $gaps = array_map(fn (int $x, int $y): int => abs($x - $y), $xs, $ys);

                $pairs[] = [
                    'judges' => [$this->judgeLabel($a), $this->judgeLabel($b)],
                    'candidates' => count($shared),
                    'r' => count($shared) < self::MIN_PAIR_CANDIDATES ? null : $this->roundedCorrelation($xs, $ys),
                    'averageGap' => $gaps === [] ? null : round(array_sum($gaps) / count($gaps), 2),
                    'largestGap' => $gaps === [] ? null : max($gaps),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int, array<int, int>>  $totals
     * @param  array<int, array{factors: array<int, int>, first: int, last: int}>  $judgeSheets
     * @param  Collection<int, ScoreFactor>  $factors
     * @param  array<int, int>  $candidateIds  candidate id => index
     * @param  array{candidates: int, plays: int}  $reopened
     * @return array<string, mixed>
     */
    private function judge(RoomJudge $judge, array $totals, array $judgeSheets, Collection $factors, array $candidateIds, array $reopened, bool $higherIsBetter): array
    {
        // Lean per candidate: this judge's total against the co-judges'
        // average on the same candidate, positive when more favorable.
        $leans = [];
        foreach ($totals[$judge->id] as $candidateId => $total) {
            $others = [];
            foreach ($totals as $otherId => $otherTotals) {
                if ($otherId !== $judge->id && isset($otherTotals[$candidateId])) {
                    $others[] = $otherTotals[$candidateId];
                }
            }
            if ($others !== []) {
                $difference = $total - array_sum($others) / count($others);
                $leans[$candidateId] = $higherIsBetter ? $difference : -$difference;
            }
        }

        $factorIds = $factors->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $fullSheets = array_filter(
            $judgeSheets,
            fn (array $sheet, int $candidateId): bool => isset($candidateIds[$candidateId]) && array_diff($factorIds, array_keys($sheet['factors'])) === [],
            ARRAY_FILTER_USE_BOTH,
        );

        return [
            'id' => $judge->id,
            'name' => (string) $judge->user->name,
            'type' => JudgeType::from($judge->getRawOriginal('judge_type'))->label(),
            'sheets' => count($fullSheets),
            'calibration' => [
                'compared' => count($leans),
                'lean' => $leans === [] ? null : round(array_sum($leans) / count($leans), 2),
            ],
            'scaleUse' => $this->scaleUse($fullSheets, $factors),
            'drift' => $this->drift($leans, $fullSheets),
            'review' => [
                'revised' => count(array_filter($fullSheets, fn (array $sheet): bool => $sheet['last'] - $sheet['first'] > self::REVIEW_AFTER_SECONDS)),
                'reopened' => $reopened['candidates'],
                'replays' => $reopened['plays'],
            ],
        ];
    }

    /**
     * How much of each factor's scale the judge used, the share of their
     * factor scores on their two most-used values, and the share of sheets
     * with the same score on every factor.
     *
     * @param  array<int, array{factors: array<int, int>, first: int, last: int}>  $fullSheets
     * @param  Collection<int, ScoreFactor>  $factors
     * @return array{valuesUsed: int, valuesPossible: int, topTwoShare: ?int, sameEveryFactor: ?int}
     */
    private function scaleUse(array $fullSheets, Collection $factors): array
    {
        $values = [];
        $flat = 0;
        foreach ($fullSheets as $sheet) {
            $scores = array_values($sheet['factors']);
            array_push($values, ...$scores);
            if (count(array_unique($scores)) === 1) {
                $flat++;
            }
        }

        $possible = (int) $factors->max(fn (ScoreFactor $factor): int => intdiv(abs((int) $factor->best - (int) $factor->worst), max(1, (int) $factor->interval_by)) + 1);

        if ($values === []) {
            return ['valuesUsed' => 0, 'valuesPossible' => $possible, 'topTwoShare' => null, 'sameEveryFactor' => null];
        }

        $counts = array_count_values($values);
        rsort($counts);

        return [
            'valuesUsed' => count(array_unique($values)),
            'valuesPossible' => $possible,
            'topTwoShare' => (int) round(array_sum(array_slice($counts, 0, 2)) / count($values) * 100),
            'sameEveryFactor' => (int) round($flat / count($fullSheets) * 100),
        ];
    }

    /**
     * The judge's lean against co-judges over the first and last thirds of
     * their roster, in the order they scored it. Measured against co-judges
     * rather than as raw totals, which change as the voice parts change.
     *
     * @param  array<int, float>  $leans  candidate id => lean
     * @param  array<int, array{factors: array<int, int>, first: int, last: int}>  $fullSheets
     * @return array{sheets: int, firstThird: ?float, lastThird: ?float, change: ?float}
     */
    private function drift(array $leans, array $fullSheets): array
    {
        $ordered = array_keys(array_intersect_key($leans, $fullSheets));
        usort($ordered, fn (int $a, int $b): int => [$fullSheets[$a]['first'], $a] <=> [$fullSheets[$b]['first'], $b]);

        if (count($ordered) < self::MIN_DRIFT_SHEETS) {
            return ['sheets' => count($ordered), 'firstThird' => null, 'lastThird' => null, 'change' => null];
        }

        $third = intdiv(count($ordered), 3);
        $average = fn (array $ids): float => array_sum(array_map(fn (int $id): float => $leans[$id], $ids)) / count($ids);
        $first = round($average(array_slice($ordered, 0, $third)), 2);
        $last = round($average(array_slice($ordered, -$third)), 2);

        return ['sheets' => count($ordered), 'firstThird' => $first, 'lastThird' => $last, 'change' => round($last - $first, 2)];
    }

    /**
     * Candidates each judge reopened after their first save, and the
     * recordings they played again then — the listening side of a review
     * pass. Only tracked from 2026-11-17 (adjudication_events).
     *
     * @param  array<int, array<int, array{factors: array<int, int>, first: int, last: int}>>  $sheets
     * @return array<int, array{candidates: int, plays: int}>
     */
    private function reopenedAfterSave(Version $version, array $sheets): array
    {
        $rows = AdjudicationEvent::where('version_id', $version->id)
            ->whereIn('event', [AdjudicationEventType::CandidateOpened->value, AdjudicationEventType::RecordingPlayed->value])
            ->toBase()
            ->get(['room_judge_id', 'candidate_id', 'event', 'created_at']);

        $candidates = [];
        $plays = [];
        foreach ($rows as $row) {
            $judgeId = (int) $row->room_judge_id;
            $candidateId = (int) $row->candidate_id;
            $firstSave = $sheets[$judgeId][$candidateId]['first'] ?? null;

            if ($firstSave === null || (int) strtotime((string) $row->created_at) <= $firstSave + 60) {
                continue;
            }

            if ($row->event === AdjudicationEventType::CandidateOpened->value) {
                $candidates[$judgeId][$candidateId] = true;
            } else {
                $plays[$judgeId] = ($plays[$judgeId] ?? 0) + 1;
            }
        }

        $result = [];
        foreach (array_unique([...array_keys($candidates), ...array_keys($plays)]) as $judgeId) {
            $result[$judgeId] = ['candidates' => count($candidates[$judgeId] ?? []), 'plays' => $plays[$judgeId] ?? 0];
        }

        return $result;
    }

    private function judgeLabel(RoomJudge $judge): string
    {
        return (string) $judge->user->name;
    }

    /**
     * Pearson correlation rounded to 2 places, or null when either side never varies.
     *
     * @param  list<int>  $xs
     * @param  list<int>  $ys
     */
    private function roundedCorrelation(array $xs, array $ys): ?float
    {
        $n = count($xs);
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;
        $varianceY = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $dx = $xs[$k] - $meanX;
            $dy = $ys[$k] - $meanY;
            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $varianceY += $dy * $dy;
        }

        return $varianceX > 0 && $varianceY > 0 ? round($covariance / sqrt($varianceX * $varianceY), 2) : null;
    }
}
