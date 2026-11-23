<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\ScoreOrder;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VersionEnsembleCutoff;
use App\Models\VoicePart;
use App\Services\CutoffStrategies\PerEnsembleCutoffStrategy;
use Illuminate\Support\Collection;

/**
 * How selection went: one row per cut-off line, i.e. per Voice Part ×
 * Ensemble under the per-Ensemble strategies (Sequential Fill, Grade-
 * Segmented), or per Voice Part under the shared-cutoff strategies
 * (docs/plans/version-analytics-report.md § Selection). For each line:
 * how many were accepted and missed, how close the closest miss was, how
 * many candidates sat within a few points either side, and a score
 * range of accepted and not-accepted totals. Plus Ensemble totals against last season
 * (EnsembleHistory) and a Version-wide outcome tally.
 *
 * Totals come from audition_results, written whenever Ensemble Cut-offs
 * accepts or rejects a candidate — the total the decision was made on.
 *
 * A line's pool is the candidates it was drawn over: everyone in the Voice
 * Part this Ensemble could take (the grade gate, for Grade-Segmented) who
 * wasn't accepted into a different Ensemble. So under Sequential Fill the
 * second Ensemble's pool starts below the first Ensemble's line.
 *
 * The shared-cutoff strategies don't store their cutoff score (only the
 * per-Ensemble strategies keep version_ensemble_cutoffs rows), so their
 * line is the worst accepted total in the Voice Part: by construction the
 * cutoff that was applied, or a score between it and the next candidate.
 * Rows say which (`lineSource`).
 */
final class SelectionAnalysisService
{
    /** "Near the line" is within this share of the line's own score, at least 1 point. */
    public const NEAR_RATIO = 0.03;

    public function __construct(
        private readonly EnsembleCutoffService $cutoffs,
        private readonly EnsembleHistoryService $history,
    ) {}

    /**
     * Null until at least one candidate has been accepted or not accepted.
     *
     * @return ?array{
     *     strategy: ?string,
     *     strategyLabel: ?string,
     *     perEnsemble: bool,
     *     higherIsBetter: bool,
     *     outcomes: array{decided: int, accepted: int, notAccepted: int, noShow: int, incomplete: int, declined: int, acceptRate: int},
     *     lines: list<array{key: string, voicePart: array{id: int, abbr: string, name: string}, ensemble: ?array{id: int, name: string, abbreviation: ?string}, line: int, lineSource: string, near: int, pool: int, accepted: int, missed: int, acceptRate: int, closestMiss: ?int, nearAccepted: int, nearMissed: int, tiedMissed: int, acceptedMedian: ?float, missedMedian: ?float, acceptedRange: ?array{int, int}, missedRange: ?array{int, int}}>,
     *     ensembles: list<array{id: int, name: string, accepted: int, priorYear: int, prior: ?int}>,
     * }
     */
    public function forVersion(Version $version): ?array
    {
        $results = AuditionResult::where('version_id', $version->id)->pluck('total', 'candidate_id');

        $outcomes = $this->outcomes($version);

        if ($outcomes['decided'] === 0) {
            return null;
        }

        // Imported legacy Versions have decisions but no audition_results
        // (their scores weren't imported), so they get outcomes and Ensemble
        // totals with no line analysis.
        $candidates = Candidate::where('version_id', $version->id)
            ->whereIn('id', $results->keys())
            ->whereIn('status', [CandidateStatus::Accepted->value, CandidateStatus::NotAccepted->value])
            ->with('student')
            ->get();

        $rawStrategy = $version->getRawOriginal('cutoff_strategy');
        $strategy = $rawStrategy === null ? null : CutoffStrategy::from($rawStrategy);
        $perEnsemble = $strategy?->needsPerEnsembleCutoff() ?? false;
        $perEnsembleStrategy = $perEnsemble ? $this->cutoffs->resolvePerEnsembleStrategy($version) : null;
        $higherIsBetter = $version->getRawOriginal('score_order') === ScoreOrder::Desc->value;

        $storedLines = VersionEnsembleCutoff::where('version_id', $version->id)->get()
            ->mapWithKeys(fn (VersionEnsembleCutoff $cutoff): array => ["{$cutoff->voice_part_id}:{$cutoff->ensemble_id}" => (int) $cutoff->score]);

        $voiceParts = VoicePart::whereIn('id', $candidates->pluck('voice_part_id')->unique())->orderBy('sort_order')->get();

        $lines = [];
        foreach ($voiceParts as $voicePart) {
            $inVoicePart = $candidates->where('voice_part_id', $voicePart->id)
                ->map(fn (Candidate $candidate): array => ['candidate' => $candidate, 'total' => (int) $results[$candidate->id]])
                ->values();

            if (! $perEnsemble) {
                $row = $this->lineRow($voicePart, null, $inVoicePart, null, $higherIsBetter);
                if ($row !== null) {
                    $lines[] = $row;
                }

                continue;
            }

            foreach ($this->cutoffs->eligibleEnsembles($version, $voicePart) as $ensemble) {
                $pool = $inVoicePart->filter(fn (array $row): bool => $this->inPool($row['candidate'], $ensemble, $perEnsembleStrategy))->values();
                $row = $this->lineRow($voicePart, $ensemble, $pool, $storedLines->get("{$voicePart->id}:{$ensemble->id}"), $higherIsBetter);
                if ($row !== null) {
                    $lines[] = $row;
                }
            }
        }

        // Group by Ensemble (the Version's Ensemble order), keeping Voice
        // Part order within each; the lines were built in Voice Part order
        // and usort is stable. Shared-cutoff lines have no Ensemble and
        // stay in Voice Part order.
        $ensembleRank = $version->ensembleOrder->values()
            ->mapWithKeys(fn ($order, int $index): array => [(int) $order->ensemble_id => $index])
            ->all();
        usort($lines, fn (array $a, array $b): int => ($ensembleRank[$a['ensemble']['id'] ?? 0] ?? -1) <=> ($ensembleRank[$b['ensemble']['id'] ?? 0] ?? -1));

        return [
            'strategy' => $rawStrategy,
            'strategyLabel' => $strategy?->label(),
            'perEnsemble' => $perEnsemble,
            'higherIsBetter' => $higherIsBetter,
            'outcomes' => $outcomes,
            'lines' => $lines,
            'ensembles' => $this->ensembles($version),
        ];
    }

    /**
     * Whether a candidate counts toward $ensemble's line: one it could take
     * and that didn't accept them into a different Ensemble.
     */
    private function inPool(Candidate $candidate, Ensemble $ensemble, ?PerEnsembleCutoffStrategy $strategy): bool
    {
        if ($strategy !== null && ! $strategy->canJoin($candidate, $ensemble)) {
            return false;
        }

        return $candidate->getRawOriginal('status') !== CandidateStatus::Accepted->value
            || (int) $candidate->accepted_ensemble_id === $ensemble->id;
    }

    /**
     * @param  Collection<int, array{candidate: Candidate, total: int}>  $pool
     * @return ?array{key: string, voicePart: array{id: int, abbr: string, name: string}, ensemble: ?array{id: int, name: string, abbreviation: ?string}, line: int, lineSource: string, near: int, pool: int, accepted: int, missed: int, acceptRate: int, closestMiss: ?int, nearAccepted: int, nearMissed: int, tiedMissed: int, acceptedMedian: ?float, missedMedian: ?float, acceptedRange: ?array{int, int}, missedRange: ?array{int, int}}
     */
    private function lineRow(VoicePart $voicePart, ?Ensemble $ensemble, Collection $pool, ?int $storedLine, bool $higherIsBetter): ?array
    {
        $isAccepted = fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::Accepted->value
            && ($ensemble === null || (int) $row['candidate']->accepted_ensemble_id === $ensemble->id);

        $accepted = $pool->filter($isAccepted)->pluck('total')->values();
        $missed = $pool->reject($isAccepted)->pluck('total')->values();

        $derived = $accepted->isEmpty() ? null : (int) ($higherIsBetter ? $accepted->min() : $accepted->max());
        $line = $storedLine ?? $derived;

        if ($line === null) {
            return null; // nobody accepted and no line drawn: nothing to analyze
        }

        $near = max(1, (int) round($line * self::NEAR_RATIO));
        $meets = fn (int $total): bool => $higherIsBetter ? $total >= $line : $total <= $line;
        $distance = fn (int $total): int => abs($total - $line);

        $missedBelow = $missed->reject($meets)->values();

        return [
            'key' => $voicePart->id.':'.($ensemble === null ? 'all' : $ensemble->id),
            'voicePart' => ['id' => $voicePart->id, 'abbr' => (string) $voicePart->abbr, 'name' => (string) $voicePart->name],
            'ensemble' => $ensemble === null ? null : ['id' => $ensemble->id, 'name' => (string) $ensemble->name, 'abbreviation' => $ensemble->abbreviation],
            'line' => $line,
            'lineSource' => $storedLine !== null ? 'set' : 'lowest_accepted',
            'near' => $near,
            'pool' => $pool->count(),
            'accepted' => $accepted->count(),
            'missed' => $missed->count(),
            'acceptRate' => $pool->isEmpty() ? 0 : (int) round($accepted->count() / $pool->count() * 100),
            'closestMiss' => $missedBelow->isEmpty() ? null : (int) $missedBelow->map($distance)->min(),
            'nearAccepted' => $accepted->filter(fn (int $total): bool => $distance($total) <= $near)->count(),
            'nearMissed' => $missedBelow->filter(fn (int $total): bool => $distance($total) <= $near)->count(),
            // Not accepted yet at or past the line: shouldn't happen, so a
            // non-zero count is worth a look on Ensemble Cut-offs.
            'tiedMissed' => $missed->filter($meets)->count(),
            'acceptedMedian' => $this->median($accepted),
            'missedMedian' => $this->median($missed),
            // Lowest and highest total on each side, for the Scorecard's range bars.
            'acceptedRange' => $accepted->isEmpty() ? null : [(int) $accepted->min(), (int) $accepted->max()],
            'missedRange' => $missed->isEmpty() ? null : [(int) $missed->min(), (int) $missed->max()],
        ];
    }

    /**
     * @param  Collection<int, int>  $values
     */
    private function median(Collection $values): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->median(), 1);
    }

    /**
     * @return array{decided: int, accepted: int, notAccepted: int, noShow: int, incomplete: int, declined: int, acceptRate: int}
     */
    private function outcomes(Version $version): array
    {
        $counts = Candidate::where('version_id', $version->id)
            ->whereIn('status', [CandidateStatus::Accepted->value, CandidateStatus::NotAccepted->value, CandidateStatus::NoShow->value, CandidateStatus::Incomplete->value, CandidateStatus::Declined->value])
            ->toBase()
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->map(fn ($count): int => (int) $count);

        // A Declined candidate was accepted first, so they count as accepted here.
        $accepted = (int) $counts->get(CandidateStatus::Accepted->value, 0) + (int) $counts->get(CandidateStatus::Declined->value, 0);
        $notAccepted = (int) $counts->get(CandidateStatus::NotAccepted->value, 0);
        $decided = $accepted + $notAccepted;

        return [
            'decided' => $decided,
            'accepted' => $accepted,
            'notAccepted' => $notAccepted,
            'noShow' => (int) $counts->get(CandidateStatus::NoShow->value, 0),
            'incomplete' => (int) $counts->get(CandidateStatus::Incomplete->value, 0),
            'declined' => (int) $counts->get(CandidateStatus::Declined->value, 0),
            'acceptRate' => $decided === 0 ? 0 : (int) round($accepted / $decided * 100),
        ];
    }

    /**
     * Each Ensemble's accepted count this Version and last season's
     * (EnsembleHistory, which Close Audition and the Ensemble History
     * screen maintain), in the Version's Ensemble order.
     *
     * @return list<array{id: int, name: string, accepted: int, priorYear: int, prior: ?int}>
     */
    private function ensembles(Version $version): array
    {
        $ensembles = $version->ensembleOrder->map(fn ($order): Ensemble => $order->ensemble)->values();
        $priorYear = $version->senior_class_of - 1;
        $grid = $this->history->historyGrid($ensembles, [$priorYear]);

        $accepted = $this->cutoffs->acceptedCounts($version)
            ->groupBy(fn (array $row): int => $row['ensemble']->id)
            ->map(fn (Collection $rows): int => (int) $rows->sum('count'));

        return $ensembles->map(fn (Ensemble $ensemble): array => [
            'id' => $ensemble->id,
            'name' => (string) $ensemble->name,
            'accepted' => (int) $accepted->get($ensemble->id, 0),
            'priorYear' => $priorYear,
            'prior' => isset($grid[$ensemble->id][$priorYear]) ? (int) array_sum($grid[$ensemble->id][$priorYear]) : null,
        ])->all();
    }
}
