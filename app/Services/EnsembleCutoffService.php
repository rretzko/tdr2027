<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\ScoreOrder;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Score;
use App\Models\Version;
use App\Models\VersionEnsembleCutoff;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\CutoffStrategies\CutoffStrategyContract;
use App\Services\CutoffStrategies\PerEnsembleCutoffStrategy;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query/business logic for the Tab Room Ensemble Cut-offs sub-module (Tab
 * Room Module.docx). Resolves and drives App\Enums\CutoffStrategy
 * implementations (app/Services/CutoffStrategies/*), which decide WHO gets
 * assigned to WHICH Ensemble; this service owns the shared plumbing every
 * strategy needs: ranking, completion classification, and the actual
 * Candidate/AuditionResult writes (see CutoffStrategyContract's docblock for
 * why accept/reject are split from finalize).
 *
 * The public orchestration methods that can change a cutoff decision
 * (applyCutoff(), applyScoreClick(), resetVoicePart(), finalizeAll()) each call
 * TabRoomReportCache::forget() once they're done — the Tab Room Reports
 * sub-module (TabRoomReportService) caches its output on the assumption
 * that a report is static until one of these methods runs, so any new write
 * path here that changes Candidate.status/accepted_ensemble_id needs the
 * same call, not just acceptCandidate()/rejectCandidate() individually
 * (those run in a loop inside a transaction; forgetting once at the end
 * avoids redundant per-candidate invalidation).
 */
final class EnsembleCutoffService
{
    public function __construct(private readonly AdjudicationService $adjudication) {}

    /**
     * Every Room serving this VoicePart in this Version — all candidates in
     * that VoicePart share this same Room set (Rooms are attached to Voice
     * Parts, not to individual Candidates).
     *
     * @return Collection<int, VersionRoom>
     */
    public function roomsForVoicePart(Version $version, VoicePart $voicePart): Collection
    {
        return VersionRoom::where('version_id', $version->id)
            ->whereHas('voiceParts', fn ($query) => $query->where('voice_parts.id', $voicePart->id))
            ->get();
    }

    /**
     * Every candidate in this VoicePart eligible to be ranked and
     * accepted/rejected: still awaiting a first decision (Registered), or
     * already decided (Accepted/NotAccepted) — the latter included so a
     * cutoff can be re-applied against a *different* score without first
     * undoing the previous decision (see rankedCandidates(),
     * applyCutoff()/applyScoreClick()). no_show/incomplete are
     * deliberately excluded — they reflect scoring facts, not a cutoff
     * decision, and re-applying a cutoff never revisits them.
     *
     * @return Collection<int, Candidate>
     */
    public function candidatesForVoicePart(Version $version, VoicePart $voicePart): Collection
    {
        return Candidate::where('version_id', $version->id)
            ->where('voice_part_id', $voicePart->id)
            ->whereIn('status', [CandidateStatus::Registered, CandidateStatus::Accepted, CandidateStatus::NotAccepted])
            ->with(['student.user', 'school', 'acceptedEnsemble'])
            ->get();
    }

    /**
     * Every Ensemble that serves this VoicePart, in the Version's
     * ensembleOrder priority — the candidate pool for SequentialEnsembleFill
     * (fill order) and AlternatingEnsembleAssignment (rotation order). A
     * VoicePart exclusive to one Ensemble naturally yields a single-entry
     * Collection here, degenerating any multi-Ensemble strategy to
     * single-Ensemble behavior for that VoicePart (see Tab Room
     * Module.docx's Tenor/Bass example).
     *
     * @return Collection<int, Ensemble>
     */
    public function eligibleEnsembles(Version $version, VoicePart $voicePart): Collection
    {
        return $version->ensembleOrder
            ->map(fn ($order) => $order->ensemble)
            ->filter(fn (Ensemble $ensemble): bool => $ensemble->voiceParts->contains('id', $voicePart->id))
            ->values();
    }

    /**
     * Combines candidateStatuses() across every Room serving this VoicePart
     * into one bucket per candidate: 'error' if any Room reports error,
     * 'completed' only if every Room reports completed, 'none' only if
     * every Room reports none, 'partial' otherwise. A Candidate scored
     * complete in their Scales Room but not yet in their Solo Room is
     * 'partial' overall, not 'completed' — cut-offs must never rank a
     * candidate on an incomplete total.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, string>
     */
    public function voicePartCompletion(Version $version, VoicePart $voicePart, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $rooms = $this->roomsForVoicePart($version, $voicePart);
        $statusesByRoom = $rooms->map(fn (VersionRoom $room) => $this->adjudication->candidateStatuses($room, $candidateIds));

        return $candidateIds->mapWithKeys(function (int $candidateId) use ($statusesByRoom): array {
            $statuses = $statusesByRoom->map(fn (array $roomStatuses): string => $roomStatuses[$candidateId] ?? 'none');

            $overall = match (true) {
                $statuses->contains('error') => 'error',
                $statuses->every(fn (string $status): bool => $status === 'completed') => 'completed',
                $statuses->every(fn (string $status): bool => $status === 'none') => 'none',
                default => 'partial',
            };

            return [$candidateId => $overall];
        })->all();
    }

    /**
     * Summed candidateTotals() across every Room serving this VoicePart, per
     * candidate — a Candidate can be adjudicated in more than one Room
     * (Scales/Solo/Quintet), so the cut-off total is the sum across all of
     * them, not any single Room's total.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, int>
     */
    public function voicePartTotals(Version $version, VoicePart $voicePart, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $rooms = $this->roomsForVoicePart($version, $voicePart);
        $totals = array_fill_keys($candidateIds->all(), 0);

        foreach ($rooms as $room) {
            foreach ($this->adjudication->candidateTotals($room, $candidateIds) as $candidateId => $total) {
                $totals[$candidateId] += $total;
            }
        }

        return $totals;
    }

    /**
     * Total Score rows entered across every Room serving this VoicePart, per
     * candidate — feeds AuditionResult.score_count.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, int>
     */
    public function voicePartScoreCounts(Version $version, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        return Score::where('version_id', $version->id)
            ->whereIn('candidate_id', $candidateIds)
            ->select('candidate_id', DB::raw('count(*) as cnt'))
            ->groupBy('candidate_id')
            ->pluck('cnt', 'candidate_id')
            ->map(fn ($cnt): int => (int) $cnt)
            ->all();
    }

    /**
     * The ranked pool a CutoffStrategy operates on: 'completed' candidates
     * only (per voicePartCompletion()), sorted best-to-worst per
     * Version::score_order — includes already-decided (Accepted/
     * NotAccepted) candidates alongside still-Registered ones, so this same
     * list drives both the first cutoff decision and any later re-decision
     * against a different score. No-show/incomplete/error candidates are
     * deliberately excluded here — see transitionNonCompletedCandidates().
     * $row['candidate']->status reflects each candidate's *current*
     * decision, which callers use to color-code an already-resolved list.
     *
     * @return Collection<int, array{candidate: Candidate, total: int, scoreCount: int}>
     */
    public function rankedCandidates(Version $version, VoicePart $voicePart): Collection
    {
        $candidates = $this->candidatesForVoicePart($version, $voicePart);
        $candidateIds = $candidates->pluck('id');

        $completion = $this->voicePartCompletion($version, $voicePart, $candidateIds);
        $totals = $this->voicePartTotals($version, $voicePart, $candidateIds);
        $scoreCounts = $this->voicePartScoreCounts($version, $candidateIds);

        $ascending = $version->getRawOriginal('score_order') === ScoreOrder::Asc->value;

        return $candidates
            ->filter(fn (Candidate $candidate): bool => ($completion[$candidate->id] ?? 'none') === 'completed')
            ->map(fn (Candidate $candidate): array => [
                'candidate' => $candidate,
                'total' => $totals[$candidate->id] ?? 0,
                'scoreCount' => $scoreCounts[$candidate->id] ?? 0,
            ])
            ->sortBy(fn (array $row): int => $ascending ? $row['total'] : -$row['total'])
            ->values();
    }

    public function meetsCutoff(Version $version, int $total, int $cutoffScore): bool
    {
        return $version->getRawOriginal('score_order') === ScoreOrder::Asc->value
            ? $total <= $cutoffScore
            : $total >= $cutoffScore;
    }

    /**
     * Transitions any Registered candidate in this VoicePart who hasn't been
     * fully scored to no_show (nothing entered) or incomplete (some, not
     * all) — done once per applyCutoff()/finalizeAll() call so
     * ranking only ever sees genuinely 'completed' candidates. Already-
     * decided (Accepted/NotAccepted) candidates are always 'completed' —
     * that's a precondition of having been decided — so this is a no-op for
     * them regardless of how many times a VoicePart's cutoff is reapplied.
     */
    public function transitionNonCompletedCandidates(Version $version, VoicePart $voicePart): void
    {
        $candidates = $this->candidatesForVoicePart($version, $voicePart);
        $completion = $this->voicePartCompletion($version, $voicePart, $candidates->pluck('id'));

        foreach ($candidates as $candidate) {
            $status = match ($completion[$candidate->id] ?? 'none') {
                'none' => CandidateStatus::NoShow,
                'partial' => CandidateStatus::Incomplete,
                default => null, // 'completed' stays Registered for ranking; 'error' needs manual Tab Room intervention
            };

            if ($status !== null) {
                $candidate->update(['status' => $status]);
            }
        }
    }

    public function acceptCandidate(Candidate $candidate, Ensemble $ensemble, int $total, int $scoreCount): void
    {
        $candidate->update(['status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
        $this->writeAuditionResult($candidate, $total, $scoreCount);
    }

    public function rejectCandidate(Candidate $candidate, int $total, int $scoreCount): void
    {
        $candidate->update(['status' => CandidateStatus::NotAccepted, 'accepted_ensemble_id' => null]);
        $this->writeAuditionResult($candidate, $total, $scoreCount);
    }

    /**
     * Marks every Candidate still Registered (i.e. genuinely never claimed
     * by any Ensemble) in this VoicePart as not_accepted — the manual
     * "sweep up the leftovers" step of finalizeAll() for per-Ensemble-cutoff strategies,
     * once every relevant Ensemble's cutoff has been applied (see
     * CutoffStrategyContract's docblock). Deliberately scoped to
     * Registered only, not every row in rankedCandidates() — that list now
     * also includes already-Accepted/NotAccepted candidates (for
     * re-decision support), which finalize must never touch.
     */
    private function finalizeVoicePart(Version $version, VoicePart $voicePart): void
    {
        foreach ($this->rankedCandidates($version, $voicePart) as $row) {
            if ($row['candidate']->getRawOriginal('status') === CandidateStatus::Registered->value) {
                $this->rejectCandidate($row['candidate'], $row['total'], $row['scoreCount']);
            }
        }

        TabRoomReportCache::forget($version);
    }

    /**
     * Resolves $version->cutoff_strategy to its implementation and applies
     * it for every Ensemble eligible for $voicePart using the SAME cutoff
     * score, then rejects anyone in the ranked pool who no longer meets
     * that score — the one-click flow for PerVoicePartPerEnsemble/
     * AlternatingEnsembleAssignment, both of which fully resolve a
     * VoicePart from a single shared score. Safe to call repeatedly against
     * a different score at any time (the "reopen and pick a new cutoff"
     * flow) — rankedCandidates() includes already-decided candidates, and
     * rejection here is a direct meetsCutoff() check against the current
     * score rather than "whoever a prior call left Registered," so a
     * previously-Accepted candidate who no longer qualifies is correctly
     * moved to NotAccepted, and vice versa.
     */
    public function applyCutoff(Version $version, VoicePart $voicePart, int $score): void
    {
        DB::transaction(function () use ($version, $voicePart, $score): void {
            $this->transitionNonCompletedCandidates($version, $voicePart);

            $eligibleEnsembles = $this->eligibleEnsembles($version, $voicePart);
            $ranked = $this->rankedCandidates($version, $voicePart);
            $strategy = $this->resolveStrategy($version);

            foreach ($eligibleEnsembles as $ensemble) {
                $strategy->apply($version, $voicePart, $ensemble, $score, $ranked, $eligibleEnsembles, $this);
            }

            foreach ($ranked as $row) {
                if (! $this->meetsCutoff($version, $row['total'], $score)) {
                    $this->rejectCandidate($row['candidate'], $row['total'], $row['scoreCount']);
                }
            }
        });

        TabRoomReportCache::forget($version);
    }

    /**
     * The applied lines for one Voice Part, in the Version's ensembleOrder
     * priority, limited to Ensembles still eligible for it (a line left
     * behind by an Ensemble later removed from the Voice Part is ignored).
     *
     * @param  Collection<int, Ensemble>  $eligibleEnsembles
     * @return Collection<int, array{ensemble: Ensemble, score: int}>
     */
    public function linesFor(Version $version, VoicePart $voicePart, Collection $eligibleEnsembles): Collection
    {
        $scores = VersionEnsembleCutoff::where('version_id', $version->id)
            ->where('voice_part_id', $voicePart->id)
            ->pluck('score', 'ensemble_id');

        return $eligibleEnsembles
            ->filter(fn (Ensemble $ensemble): bool => $scores->has($ensemble->id))
            ->map(fn (Ensemble $ensemble): array => ['ensemble' => $ensemble, 'score' => (int) $scores[$ensemble->id]])
            ->values();
    }

    /**
     * The per-Ensemble strategies' (SequentialEnsembleFill/
     * GradeSegmentedEnsembles) one-click flow: a click on a ranked row draws
     * — or moves — one Ensemble's line at that row's total, applied
     * immediately. The strategy decides which Ensemble the click targets
     * (PerEnsembleCutoffStrategy::targetEnsemble()); the line is stored as
     * a VersionEnsembleCutoff and the whole Voice Part is recomputed from
     * every stored line (recomputeVoicePart()), so moving an earlier
     * Ensemble's line correctly re-flows candidates into the Ensembles
     * after it. Nothing is rejected here — candidates below every line stay
     * Registered until finalizeAll().
     *
     * @return array{ensemble: Ensemble, score: int}|null null when the candidate isn't rankable or no eligible Ensemble can take them
     */
    public function applyScoreClick(Version $version, VoicePart $voicePart, int $candidateId): ?array
    {
        // No transitionNonCompletedCandidates() here, unlike applyCutoff():
        // clicks happen while judges may still be scoring, and marking a
        // half-scored candidate Incomplete (or an unscored one No Show)
        // would drop them from candidatesForVoicePart() for good. Only
        // finalizeAll() does that, after finalizeSummary() has refused on
        // any partial scoring.
        $result = DB::transaction(function () use ($version, $voicePart, $candidateId): ?array {
            $row = $this->rankedCandidates($version, $voicePart)
                ->first(fn (array $row): bool => $row['candidate']->id === $candidateId);

            if ($row === null) {
                return null;
            }

            $applied = VersionEnsembleCutoff::where('version_id', $version->id)
                ->where('voice_part_id', $voicePart->id)
                ->pluck('ensemble_id')
                ->map(fn ($id): int => (int) $id);

            $target = $this->resolvePerEnsembleStrategy($version)
                ->targetEnsemble($row['candidate'], $this->eligibleEnsembles($version, $voicePart), $applied);

            if ($target === null) {
                return null;
            }

            VersionEnsembleCutoff::updateOrCreate(
                ['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'ensemble_id' => $target->id],
                ['score' => $row['total']],
            );

            $this->recomputeVoicePart($version, $voicePart);

            return ['ensemble' => $target, 'score' => $row['total']];
        });

        TabRoomReportCache::forget($version);

        return $result;
    }

    /**
     * Re-derives every ranked candidate's Ensemble in this Voice Part from
     * the stored lines and writes only the differences: newly claimed
     * candidates are accepted (including a previously NotAccepted one a
     * moved line now reaches), and an Accepted candidate no line claims any
     * more goes back to Registered — undecided, pending finalizeAll() —
     * rather than straight to NotAccepted, since a later line (the next
     * Ensemble's) may still claim them.
     */
    public function recomputeVoicePart(Version $version, VoicePart $voicePart): void
    {
        $eligibleEnsembles = $this->eligibleEnsembles($version, $voicePart);
        $ensemblesById = $eligibleEnsembles->keyBy('id');
        $ranked = $this->rankedCandidates($version, $voicePart);

        $assignments = $this->resolvePerEnsembleStrategy($version)
            ->assign($version, $ranked, $this->linesFor($version, $voicePart, $eligibleEnsembles), $this);

        foreach ($ranked as $row) {
            $candidate = $row['candidate'];
            $isAccepted = $candidate->getRawOriginal('status') === CandidateStatus::Accepted->value;
            $targetId = $assignments[$candidate->id] ?? null;

            if ($targetId !== null) {
                if (! $isAccepted || $candidate->accepted_ensemble_id !== $targetId) {
                    $this->acceptCandidate($candidate, $ensemblesById[$targetId], $row['total'], $row['scoreCount']);
                }
            } elseif ($isAccepted) {
                $this->revertToRegistered($candidate);
            }
        }
    }

    /**
     * Clears every line in this Voice Part and returns every decided
     * (Accepted/NotAccepted) candidate in it to Registered — the per-Ensemble
     * strategies' "start this Voice Part over".
     */
    public function resetVoicePart(Version $version, VoicePart $voicePart): void
    {
        DB::transaction(function () use ($version, $voicePart): void {
            VersionEnsembleCutoff::where('version_id', $version->id)
                ->where('voice_part_id', $voicePart->id)
                ->delete();

            $this->candidatesForVoicePart($version, $voicePart)
                ->reject(fn (Candidate $candidate): bool => $candidate->getRawOriginal('status') === CandidateStatus::Registered->value)
                ->each(fn (Candidate $candidate) => $this->revertToRegistered($candidate));
        });

        TabRoomReportCache::forget($version);
    }

    /**
     * Everything that would block, or happen during, finalizeAll() across
     * every Voice Part in the Version — the guard that keeps a single
     * event-wide Finalize from orphaning anyone. Blocking problems:
     *  - partially scored candidates (they'd silently become Incomplete);
     *  - candidates with more scores than the Room expects ('error');
     *  - out-of-tolerance candidates (their total isn't trustworthy);
     *  - completed candidates whose voice part no Ensemble includes, or
     *    whose grade no eligible Ensemble accepts (they could never be
     *    accepted — fix the Ensemble setup first);
     *  - an eligible Ensemble with no line yet while candidates it could
     *    still claim remain unclaimed (otherwise a whole Voice Part, or a
     *    whole grade band, would be rejected because a click was missed).
     * Unscored candidates are not a problem — they become No Show.
     *
     * @return array{problems: list<string>, rejectCount: int, noShowCount: int}
     */
    public function finalizeSummary(Version $version): array
    {
        $strategy = $this->resolvePerEnsembleStrategy($version);
        $problems = [];
        $rejectCount = 0;
        $noShowCount = 0;

        foreach ($version->availableVoiceParts() as $voicePart) {
            $candidates = $this->candidatesForVoicePart($version, $voicePart);

            if ($candidates->isEmpty()) {
                continue;
            }

            $label = $voicePart->abbr;
            $completion = collect($this->voicePartCompletion($version, $voicePart, $candidates->pluck('id')));
            $noShowCount += $completion->filter(fn (string $status): bool => $status === 'none')->count();

            $partial = $completion->filter(fn (string $status): bool => $status === 'partial')->count();
            if ($partial > 0) {
                $problems[] = "{$label}: {$partial} ".str('candidate')->plural($partial).' only partially scored.';
            }

            $overScored = $completion->filter(fn (string $status): bool => $status === 'error')->count();
            if ($overScored > 0) {
                $problems[] = "{$label}: {$overScored} ".str('candidate')->plural($overScored).' with more scores than the Room expects.';
            }

            $ranked = $this->rankedCandidates($version, $voicePart);
            if ($ranked->isEmpty()) {
                continue;
            }

            $rankedIds = $ranked->pluck('candidate.id');
            $outOfTolerance = $this->roomsForVoicePart($version, $voicePart)
                ->flatMap(fn (VersionRoom $room) => collect($this->adjudication->candidateTolerances($room, $rankedIds))->filter(fn (bool $ok): bool => ! $ok)->keys())
                ->unique()
                ->count();
            if ($outOfTolerance > 0) {
                $problems[] = "{$label}: {$outOfTolerance} ".str('candidate')->plural($outOfTolerance).' out of tolerance.';
            }

            $eligibleEnsembles = $this->eligibleEnsembles($version, $voicePart);
            if ($eligibleEnsembles->isEmpty()) {
                $problems[] = "{$label}: no Ensemble includes this voice part.";
                $rejectCount += $ranked->count();

                continue;
            }

            $homeless = $ranked->filter(fn (array $row): bool => $eligibleEnsembles->every(fn (Ensemble $ensemble): bool => ! $strategy->canJoin($row['candidate'], $ensemble)))->count();
            if ($homeless > 0) {
                $problems[] = "{$label}: {$homeless} ".str('candidate')->plural($homeless).' in a grade no Ensemble for this voice part accepts.';
            }

            // Judged against what the lines claim from the CURRENT scores —
            // the same recompute finalizeAll() runs first — not against
            // stored statuses, which can lag a score edited after a click.
            $lines = $this->linesFor($version, $voicePart, $eligibleEnsembles);
            $assignments = $strategy->assign($version, $ranked, $lines, $this);
            $unclaimed = $ranked->reject(fn (array $row): bool => isset($assignments[$row['candidate']->id]));
            $rejectCount += $unclaimed->count();

            $appliedIds = $lines->map(fn (array $line): int => $line['ensemble']->id);

            foreach ($eligibleEnsembles as $ensemble) {
                if (! $appliedIds->contains($ensemble->id) && $unclaimed->contains(fn (array $row): bool => $strategy->canJoin($row['candidate'], $ensemble))) {
                    $problems[] = "{$label}: no cutoff selected yet for {$ensemble->name}.";
                }
            }
        }

        return ['problems' => $problems, 'rejectCount' => $rejectCount, 'noShowCount' => $noShowCount];
    }

    /**
     * The per-Ensemble strategies' single, event-wide Finalize: unscored
     * candidates become No Show and every completed candidate no line
     * claimed becomes NotAccepted, across every Voice Part at once. Each
     * Voice Part is recomputed from its lines first, so a score edited
     * after the last click is honored rather than rejected on a stale
     * status. Refuses (returns the blocking problems, changing nothing)
     * while finalizeSummary() reports any.
     *
     * @return list<string> blocking problems; empty when finalized
     */
    public function finalizeAll(Version $version): array
    {
        $problems = $this->finalizeSummary($version)['problems'];

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($version): void {
            foreach ($version->availableVoiceParts() as $voicePart) {
                $this->transitionNonCompletedCandidates($version, $voicePart);
                $this->recomputeVoicePart($version, $voicePart);
                $this->finalizeVoicePart($version, $voicePart);
            }
        });

        TabRoomReportCache::forget($version);

        return [];
    }

    private function revertToRegistered(Candidate $candidate): void
    {
        $candidate->update(['status' => CandidateStatus::Registered, 'accepted_ensemble_id' => null]);
        AuditionResult::where('candidate_id', $candidate->id)->delete();
    }

    /**
     * $version->cutoff_strategy is a magic-cast property (Larastan can't
     * infer its CutoffStrategy type through the casts() accessor), so it's
     * resolved once, explicitly, here rather than at every call site.
     */
    private function resolveStrategy(Version $version): CutoffStrategyContract
    {
        $strategy = $this->resolveAnyStrategy($version);
        abort_unless($strategy instanceof CutoffStrategyContract, 422, 'This cut-off strategy sets a separate cutoff per Ensemble.');

        return $strategy;
    }

    public function resolvePerEnsembleStrategy(Version $version): PerEnsembleCutoffStrategy
    {
        $strategy = $this->resolveAnyStrategy($version);
        abort_unless($strategy instanceof PerEnsembleCutoffStrategy, 422, 'This cut-off strategy applies one shared cutoff per voice part.');

        return $strategy;
    }

    private function resolveAnyStrategy(Version $version): CutoffStrategyContract|PerEnsembleCutoffStrategy
    {
        $rawStrategy = $version->getRawOriginal('cutoff_strategy');
        abort_if($rawStrategy === null, 422, 'Configure a cut-off strategy on this Version before using Ensemble Cut-offs.');

        return app(CutoffStrategy::from($rawStrategy)->strategyClass());
    }

    /**
     * Count of accepted candidates grouped by Ensemble and VoicePart, plus
     * per-Ensemble totals — the Ensemble Cut-offs page's summary table.
     *
     * @return Collection<int, array{ensemble: Ensemble, voice_part_id: int, count: int}>
     */
    public function acceptedCounts(Version $version): Collection
    {
        $rows = DB::table('candidates')
            ->where('version_id', $version->id)
            ->where('status', CandidateStatus::Accepted->value)
            ->whereNotNull('accepted_ensemble_id')
            ->select('accepted_ensemble_id', 'voice_part_id', DB::raw('count(*) as cnt'))
            ->groupBy('accepted_ensemble_id', 'voice_part_id')
            ->get();

        $ensemblesById = Ensemble::whereIn('id', $rows->pluck('accepted_ensemble_id')->unique())->get()->keyBy('id');

        return $rows->map(fn ($row): array => [
            'ensemble' => $ensemblesById->get($row->accepted_ensemble_id),
            'voice_part_id' => (int) $row->voice_part_id,
            'count' => (int) $row->cnt,
        ]);
    }

    private function writeAuditionResult(Candidate $candidate, int $total, int $scoreCount): void
    {
        AuditionResult::updateOrCreate(
            ['candidate_id' => $candidate->id],
            [
                'version_id' => $candidate->version_id,
                'voice_part_id' => $candidate->voice_part_id,
                'school_id' => $candidate->school_id,
                'voice_part_order_by' => $candidate->voicePart->sort_order,
                'score_count' => $scoreCount,
                'total' => $total,
            ],
        );
    }
}
