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
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\CutoffStrategies\CutoffStrategyContract;
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
     * applyCutoff()/applyEnsembleCutoff()). no_show/incomplete are
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
     * all) — done once per applyCutoff()/applyEnsembleCutoff() call so
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
     * "sweep up the leftovers" step for per-Ensemble-cutoff strategies,
     * once every relevant Ensemble's cutoff has been applied (see
     * CutoffStrategyContract's docblock). Deliberately scoped to
     * Registered only, not every row in rankedCandidates() — that list now
     * also includes already-Accepted/NotAccepted candidates (for
     * re-decision support), which finalize must never touch.
     */
    public function finalizeVoicePart(Version $version, VoicePart $voicePart): void
    {
        foreach ($this->rankedCandidates($version, $voicePart) as $row) {
            if ($row['candidate']->getRawOriginal('status') === CandidateStatus::Registered->value) {
                $this->rejectCandidate($row['candidate'], $row['total'], $row['scoreCount']);
            }
        }
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
    }

    /**
     * Applies a cutoff to exactly one Ensemble, without finalizing — the
     * flow for SequentialEnsembleFill/GradeSegmentedEnsembles, which need a
     * distinct score per Ensemble entered one at a time. The manager calls
     * finalizeVoicePart() explicitly once every Ensemble has its cutoff.
     *
     * The ranked pool passed to the Strategy excludes candidates already
     * accepted into a *different* Ensemble, so re-applying this Ensemble's
     * cutoff (e.g. after reopening) never poaches another Ensemble's
     * already-decided members — only candidates still undecided, or
     * previously accepted into this same Ensemble, are reconsidered.
     * Candidates previously in this Ensemble who no longer meet the new
     * score are rejected directly, the same reasoning as applyCutoff().
     */
    public function applyEnsembleCutoff(Version $version, VoicePart $voicePart, Ensemble $ensemble, int $score): void
    {
        DB::transaction(function () use ($version, $voicePart, $ensemble, $score): void {
            $this->transitionNonCompletedCandidates($version, $voicePart);

            $eligibleEnsembles = $this->eligibleEnsembles($version, $voicePart);
            $ranked = $this->rankedCandidates($version, $voicePart)
                ->filter(fn (array $row): bool => $row['candidate']->accepted_ensemble_id === null || $row['candidate']->accepted_ensemble_id === $ensemble->id)
                ->values();
            $strategy = $this->resolveStrategy($version);

            $strategy->apply($version, $voicePart, $ensemble, $score, $ranked, $eligibleEnsembles, $this);

            foreach ($ranked as $row) {
                if ($row['candidate']->accepted_ensemble_id === $ensemble->id && ! $this->meetsCutoff($version, $row['total'], $score)) {
                    $this->rejectCandidate($row['candidate'], $row['total'], $row['scoreCount']);
                }
            }
        });
    }

    /**
     * $version->cutoff_strategy is a magic-cast property (Larastan can't
     * infer its CutoffStrategy type through the casts() accessor), so it's
     * resolved once, explicitly, here rather than at every call site.
     */
    private function resolveStrategy(Version $version): CutoffStrategyContract
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
