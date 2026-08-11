<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query/business logic for the judge Adjudication page (Adjudicate.php).
 * Candidate<->Room membership is derived live from voice part — no manual
 * assignment table exists or is planned; a Candidate belongs to a Room iff
 * its voice_part_id is among that Room's configured voiceParts() and its
 * status is one of CandidateStatus::roomTrackingStates() (see Adjudication
 * Structure.docx). Widened from "status is Registered" once Ensemble
 * Cut-offs (EnsembleCutoffService) started transitioning candidates to
 * accepted/not_accepted/no_show/incomplete directly from Registered — a
 * Registered-only scope silently dropped a Candidate from their Room's
 * roster and progress the instant a cutoff was applied for their Voice
 * Part, which broke Adjudication Tracking's post-cutoff visibility.
 */
final class AdjudicationService
{
    /**
     * @return Collection<int, Candidate>
     */
    public function candidatesForRoom(VersionRoom $room): Collection
    {
        $voicePartIds = $room->voiceParts->pluck('id');

        return Candidate::where('version_id', $room->version_id)
            ->whereIn('voice_part_id', $voicePartIds)
            ->whereIn('status', CandidateStatus::roomTrackingStates())
            ->with('voicePart')
            ->get()
            // Single-callback tuple form, not the multi-criteria array form —
            // see dashboard.blade.php's own comment on why the latter
            // silently misorders with 1-arg value-extractor callbacks.
            ->sortBy(fn (Candidate $candidate): array => [$candidate->voicePart->sort_order, $candidate->id])
            ->values();
    }

    /**
     * @return Collection<int, ScoreCategory>
     */
    public function roomRubric(VersionRoom $room): Collection
    {
        return $room->scoreCategories()->with('scoreFactors')->get();
    }

    public function factorCount(VersionRoom $room): int
    {
        return $this->roomRubric($room)->sum(fn (ScoreCategory $category): int => $category->scoreFactors->count());
    }

    /**
     * A Candidate can be adjudicated in more than one Room in the same
     * Version (e.g. separate Scales/Solo/Quintet rooms, each with its own
     * judge trio) — scores has no room_id column, so every aggregate that
     * reads it must scope explicitly to this Room's own judges via this
     * helper, never just version_id + candidate_id, or it silently pulls in
     * another Room's unrelated judges/scores for the same Candidate.
     *
     * @return Collection<int, int>
     */
    private function roomJudgeIds(VersionRoom $room): Collection
    {
        return RoomJudge::where('room_id', $room->id)->pluck('id');
    }

    /**
     * A Candidate's approved recordings, matched to the Room's rubric by
     * comparing Recording::file_type to score_categories.description
     * case-insensitively — file_type *is* the score category for this data
     * set (confirmed with the product owner), not merely similar text.
     * Ordered by score_categories.order_by, per Adjudication Structure.docx.
     * Unapproved recordings (null approved_at/approved_by) are never
     * returned — judges don't see anything staff hasn't cleared.
     *
     * @return Collection<int, Recording>
     */
    public function recordingsForCandidate(VersionRoom $room, Candidate $candidate): Collection
    {
        $categoryOrderByName = $this->roomRubric($room)
            ->mapWithKeys(fn (ScoreCategory $category): array => [strtolower(trim($category->description)) => $category->order_by]);

        return Recording::where('version_id', $room->version_id)
            ->where('candidate_id', $candidate->id)
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_by')
            ->get()
            ->filter(fn (Recording $recording): bool => $categoryOrderByName->has(strtolower(trim($recording->file_type))))
            ->sortBy(fn (Recording $recording): int => $categoryOrderByName[strtolower(trim($recording->file_type))])
            ->values();
    }

    /**
     * The stepped best->worst range for a factor's dropdown, best-first
     * regardless of whether best is numerically higher or lower than worst.
     * Shared by the Blade select and save()'s validation rule so the two
     * can never diverge.
     *
     * @return list<int>
     */
    public function optionsForFactor(ScoreFactor $factor): array
    {
        $best = (int) $factor->best;
        $worst = (int) $factor->worst;
        $step = (int) $factor->interval_by;

        $options = [];

        if ($best <= $worst) {
            for ($value = $best; $value <= $worst; $value += $step) {
                $options[] = $value;
            }
        } else {
            for ($value = $best; $value >= $worst; $value -= $step) {
                $options[] = $value;
            }
        }

        return $options;
    }

    /**
     * One aggregate query bucketed against judgeCount * factorCount, not a
     * per-candidate query loop.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, string> candidate_id => 'none'|'partial'|'completed'|'error'
     */
    public function candidateStatuses(VersionRoom $room, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $judgeIds = $this->roomJudgeIds($room);
        $max = $judgeIds->count() * $this->factorCount($room);

        $counts = Score::where('version_id', $room->version_id)
            ->whereIn('candidate_id', $candidateIds)
            ->whereIn('judge_id', $judgeIds)
            ->select('candidate_id', DB::raw('count(*) as cnt'))
            ->groupBy('candidate_id')
            ->pluck('cnt', 'candidate_id');

        return $candidateIds->mapWithKeys(function (int $candidateId) use ($counts, $max): array {
            $count = (int) ($counts[$candidateId] ?? 0);

            $status = match (true) {
                $count === 0 => 'none',
                $count === $max => 'completed',
                $count < $max => 'partial',
                default => 'error',
            };

            return [$candidateId => $status];
        })->all();
    }

    /**
     * A null Room tolerance means "not applied" (VersionRoom's own
     * docblock) — always in tolerance. A candidate with no scores yet is
     * also always in tolerance (nothing to compare).
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, bool>
     */
    public function candidateTolerances(VersionRoom $room, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $tolerance = $room->tolerance === null ? null : (int) $room->tolerance;

        $totalsByCandidate = Score::where('version_id', $room->version_id)
            ->whereIn('candidate_id', $candidateIds)
            ->whereIn('judge_id', $this->roomJudgeIds($room))
            ->select('candidate_id', 'judge_id', DB::raw('sum(score) as total'))
            ->groupBy('candidate_id', 'judge_id')
            ->get()
            ->groupBy('candidate_id');

        return $candidateIds->mapWithKeys(function (int $candidateId) use ($totalsByCandidate, $tolerance): array {
            if ($tolerance === null) {
                return [$candidateId => true];
            }

            $totals = $totalsByCandidate->get($candidateId, collect())->pluck('total');

            if ($totals->isEmpty()) {
                return [$candidateId => true];
            }

            return [$candidateId => ($totals->max() - $totals->min()) <= $tolerance];
        })->all();
    }

    /**
     * Whether any Candidate assigned to this Room is currently out of
     * tolerance — a coarser, Room-level boolean over candidateTolerances()'s
     * per-candidate map, for a Room-level indicator (e.g. Tab Room's Rooms
     * selector asterisk).
     */
    public function roomHasOutOfToleranceCandidate(VersionRoom $room): bool
    {
        $candidateIds = $this->candidatesForRoom($room)->pluck('id');

        return in_array(false, $this->candidateTolerances($room, $candidateIds), true);
    }

    /**
     * Weighted grand total across every judge who has scored so far,
     * honoring each factor's multiplier — the same weighting the
     * Adjudicate view's client-side Alpine preview already applies to a
     * single judge's in-progress selections, now available server-side
     * across all judges. Computed live via a single grouped aggregate
     * query, the same pattern as candidateStatuses()/candidateTolerances()
     * — not a stored running counter, since Score::updateOrCreate() makes
     * every recomputation cheap and correct even after a judge revises a
     * prior save. This is the number Tab Room progress views read while
     * adjudication is still open; AuditionResult freezes it once, at
     * Version close.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, int>
     */
    public function candidateTotals(VersionRoom $room, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $multipliers = $this->roomRubric($room)
            ->flatMap(fn (ScoreCategory $category): Collection => $category->scoreFactors)
            ->pluck('multiplier', 'id');

        $factorSumsByCandidate = Score::where('version_id', $room->version_id)
            ->whereIn('candidate_id', $candidateIds)
            ->whereIn('judge_id', $this->roomJudgeIds($room))
            ->select('candidate_id', 'score_factor_id', DB::raw('sum(score) as factor_sum'))
            ->groupBy('candidate_id', 'score_factor_id')
            ->get()
            ->groupBy('candidate_id');

        return $candidateIds->mapWithKeys(function (int $candidateId) use ($factorSumsByCandidate, $multipliers): array {
            $total = $factorSumsByCandidate->get($candidateId, collect())
                ->sum(fn ($row): int => (int) $row->factor_sum * (int) ($multipliers[$row->score_factor_id] ?? 1));

            return [$candidateId => (int) $total];
        })->all();
    }

    /**
     * Room-level rollup of candidateStatuses() — status-bucket counts
     * across every Candidate assigned to this Room, one level up from the
     * per-candidate roster the Adjudicate page shows. Feeds Tab Room's
     * per-room progress view.
     *
     * @return array{completed: int, partial: int, none: int, error: int}
     */
    public function roomProgress(VersionRoom $room): array
    {
        $candidateIds = $this->candidatesForRoom($room)->pluck('id');
        $statuses = $this->candidateStatuses($room, $candidateIds);

        $counts = ['completed' => 0, 'partial' => 0, 'none' => 0, 'error' => 0];

        foreach ($statuses as $status) {
            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * Per-room progress across every Room configured for a Version — Tab
     * Room's version-wide rollup, one level up from roomProgress().
     *
     * @return Collection<int, array{room: VersionRoom, completed: int, partial: int, none: int, error: int}>
     */
    public function versionProgress(Version $version): Collection
    {
        return VersionRoom::where('version_id', $version->id)
            ->get()
            ->map(fn (VersionRoom $room): array => ['room' => $room, ...$this->roomProgress($room)]);
    }

    /**
     * Whether *this judge's own* score count for each candidate equals the
     * room's full factor count — drives the roster checkmark, independent
     * of the room-wide candidateStatuses() bucket.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, bool>
     */
    public function judgeCompletionFor(RoomJudge $judge, Collection $candidateIds, int $factorCount): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $counts = Score::where('judge_id', $judge->id)
            ->whereIn('candidate_id', $candidateIds)
            ->select('candidate_id', DB::raw('count(*) as cnt'))
            ->groupBy('candidate_id')
            ->pluck('cnt', 'candidate_id');

        return $candidateIds->mapWithKeys(fn (int $candidateId): array => [
            $candidateId => (int) ($counts[$candidateId] ?? 0) === $factorCount,
        ])->all();
    }

    /**
     * Ordered by JudgeType's own declaration order (Head/Lead Judge,
     * Judge 1..4, Judge Monitor, Monitor) rather than a separately
     * maintained priority list.
     *
     * @return Collection<int, RoomJudge>
     */
    public function roomJudgesOrdered(VersionRoom $room): Collection
    {
        return RoomJudge::where('room_id', $room->id)
            ->with('user.teacher.schools')
            ->get()
            ->sortBy(fn (RoomJudge $roomJudge): int => $this->judgeTypeRank($roomJudge->getRawOriginal('judge_type')))
            ->values();
    }

    /**
     * Scoped to this Room's own judges — a Candidate can be adjudicated in
     * more than one Room (see roomJudgeIds()), so this must never fall back
     * to just version_id + candidate_id.
     *
     * @return Collection<int|string, EloquentCollection<int, Score>>
     */
    public function scoresForCandidate(VersionRoom $room, Candidate $candidate): Collection
    {
        return Score::where('version_id', $room->version_id)
            ->where('candidate_id', $candidate->id)
            ->whereIn('judge_id', $this->roomJudgeIds($room))
            ->get()
            ->groupBy('judge_id');
    }

    /**
     * A judge's own prior save for this candidate, keyed by score_factor_id.
     * Anything not yet scored is simply absent — never defaulted.
     *
     * @return array<int, int>
     */
    public function existingScoresFor(RoomJudge $judge, Candidate $candidate): array
    {
        return Score::where('judge_id', $judge->id)
            ->where('candidate_id', $candidate->id)
            ->pluck('score', 'score_factor_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $scoresByFactorId
     */
    public function saveScores(RoomJudge $judge, Candidate $candidate, Version $version, array $scoresByFactorId, ?int $overriddenByUserId = null): void
    {
        TabRoomReportCache::forget($version);

        $room = $judge->room;
        $judgeOrderBy = $this->judgeTypeRank($judge->getRawOriginal('judge_type')) + 1;

        foreach ($this->roomRubric($room) as $category) {
            foreach ($category->scoreFactors as $factor) {
                if (! array_key_exists($factor->id, $scoresByFactorId)) {
                    continue;
                }

                Score::updateOrCreate(
                    [
                        'version_id' => $version->id,
                        'candidate_id' => $candidate->id,
                        'judge_id' => $judge->id,
                        'score_factor_id' => $factor->id,
                    ],
                    [
                        'student_id' => $candidate->student_id,
                        'school_id' => $candidate->school_id,
                        'score_category_id' => $category->id,
                        'score_category_order_by' => $category->order_by,
                        'score_factor_order_by' => $factor->order_by,
                        'judge_order_by' => $judgeOrderBy,
                        'voice_part_id' => $candidate->voice_part_id,
                        'voice_part_order_by' => $candidate->voicePart->sort_order,
                        'score' => $scoresByFactorId[$factor->id],
                        'overridden_by_user_id' => $overriddenByUserId,
                        'overridden_at' => $overriddenByUserId !== null ? now() : null,
                    ],
                );
            }
        }
    }

    /**
     * Candidate lookup for the Tab Room Add/Edit Scores search box — by
     * exact candidates.id, or by student last name. Only ever matches
     * status=Registered candidates in this Version, per Tab Room Module.docx.
     *
     * @return array{candidate: ?Candidate, matches: Collection<int, Candidate>}
     */
    public function findCandidate(Version $version, ?string $candidateId, ?string $lastName): array
    {
        if ($candidateId !== null && $candidateId !== '') {
            $candidate = Candidate::where('version_id', $version->id)
                ->where('id', $candidateId)
                ->where('status', CandidateStatus::Registered)
                ->first();

            return ['candidate' => $candidate, 'matches' => collect()];
        }

        if ($lastName === null || $lastName === '') {
            return ['candidate' => null, 'matches' => collect()];
        }

        $studentIds = Student::whereHas('user', fn ($query) => $query->where('last_name', 'like', "{$lastName}%"))
            ->pluck('id');

        $matches = Candidate::where('version_id', $version->id)
            ->whereIn('student_id', $studentIds)
            ->where('status', CandidateStatus::Registered)
            ->with(['student.user', 'voicePart'])
            ->get();

        if ($matches->count() === 1) {
            return ['candidate' => $matches->first(), 'matches' => collect()];
        }

        return ['candidate' => null, 'matches' => $matches];
    }

    /**
     * Every Room a Candidate is adjudicated in — a Candidate can span more
     * than one Room in the same Version (e.g. separate Scales/Solo/Quintet
     * Rooms), same membership rule as candidatesForRoom() applied in reverse.
     *
     * @return Collection<int, VersionRoom>
     */
    public function roomsForCandidate(Candidate $candidate): Collection
    {
        return VersionRoom::where('version_id', $candidate->version_id)
            ->whereHas('voiceParts', fn ($query) => $query->where('voice_parts.id', $candidate->voice_part_id))
            ->get();
    }

    /**
     * A single Candidate's grand total across every Room they're
     * adjudicated in (Scales/Solo/Quintet each a separate Room) — the
     * number Ensemble Cut-offs ranks and compares against a cutoff score.
     * For ranking many candidates in the same VoicePart at once, use
     * EnsembleCutoffService::voicePartTotals() instead — it batches the
     * same per-Room candidateTotals() calls across every candidate rather
     * than looping this method, which would be a query-per-candidate N+1.
     */
    public function versionCandidateTotal(Candidate $candidate): int
    {
        $candidateIds = collect([$candidate])->pluck('id');

        return $this->roomsForCandidate($candidate)
            ->sum(fn (VersionRoom $room): int => $this->candidateTotals($room, $candidateIds)[$candidate->id] ?? 0);
    }

    /**
     * The Tab Room Manager's destructive voice-part change (Tab Room
     * Module.docx): "Changing voice parts after auditions have started will
     * remove ALL previously entered scores and any non-relevant recordings
     * for this candidate." Removes every Score for this Candidate across
     * every Room (not just the Room currently being viewed), then removes
     * any Recording whose file_type no longer matches a score category
     * reachable from the *new* voice part's Rooms — the inverse of the
     * matching rule in recordingsForCandidate().
     */
    public function changeVoicePart(Candidate $candidate, VoicePart $newVoicePart): void
    {
        DB::transaction(function () use ($candidate, $newVoicePart): void {
            Score::where('version_id', $candidate->version_id)
                ->where('candidate_id', $candidate->id)
                ->delete();

            $newRooms = VersionRoom::where('version_id', $candidate->version_id)
                ->whereHas('voiceParts', fn ($query) => $query->where('voice_parts.id', $newVoicePart->id))
                ->get();

            $relevantCategoryNames = $newRooms
                ->flatMap(fn (VersionRoom $room): Collection => $this->roomRubric($room))
                ->map(fn (ScoreCategory $category): string => strtolower(trim($category->description)))
                ->unique();

            Recording::where('version_id', $candidate->version_id)
                ->where('candidate_id', $candidate->id)
                ->get()
                ->each(function (Recording $recording) use ($relevantCategoryNames): void {
                    if (! $relevantCategoryNames->contains(strtolower(trim($recording->file_type)))) {
                        $recording->delete();
                    }
                });

            $candidate->update(['voice_part_id' => $newVoicePart->id]);
        });
    }

    /**
     * Per-judge score summary for a single Candidate in a Room — factors
     * entered vs. the Room's full factor count, and that judge's own total.
     * Feeds Add/Edit Scores' Judge Summary Table, where only one Candidate
     * is ever on screen at a time. For a whole Room's worth of Candidates
     * (Adjudication Tracking's tooltip), use candidateJudgeBreakdown()
     * instead — this one query-per-candidate shape would be an N+1 there.
     *
     * @return Collection<int, array{judge: RoomJudge, enteredCount: int, factorCount: int, total: int}>
     */
    public function judgeScoreSummaryForCandidate(VersionRoom $room, Candidate $candidate): Collection
    {
        $factorCount = $this->factorCount($room);
        $scoresByJudge = $this->scoresForCandidate($room, $candidate);

        return $this->roomJudgesOrdered($room)->map(function (RoomJudge $judge) use ($scoresByJudge, $factorCount): array {
            $judgeScores = $scoresByJudge->get($judge->id, collect());

            return [
                'judge' => $judge,
                'enteredCount' => $judgeScores->count(),
                'factorCount' => $factorCount,
                'total' => (int) $judgeScores->sum('score'),
            ];
        });
    }

    /**
     * The same per-judge breakdown as judgeScoreSummaryForCandidate(), for
     * every Candidate in $candidateIds at once — one aggregate query, not a
     * per-candidate loop (same convention as candidateStatuses()/
     * candidateTolerances()). Feeds Adjudication Tracking's per-candidate
     * hover tooltip (candidate name, teacher @ school, and each judge's
     * score count possible/entered + total, per Tab Room Module.docx).
     *
     * @param  Collection<int, int>  $candidateIds
     * @return array<int, Collection<int, array{judge: RoomJudge, enteredCount: int, factorCount: int, total: int}>>
     */
    public function candidateJudgeBreakdown(VersionRoom $room, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $judges = $this->roomJudgesOrdered($room);
        $factorCount = $this->factorCount($room);

        $rowsByCandidate = Score::where('version_id', $room->version_id)
            ->whereIn('candidate_id', $candidateIds)
            ->whereIn('judge_id', $judges->pluck('id'))
            ->select('candidate_id', 'judge_id', DB::raw('count(*) as cnt'), DB::raw('sum(score) as total'))
            ->groupBy('candidate_id', 'judge_id')
            ->get()
            ->groupBy('candidate_id');

        return $candidateIds->mapWithKeys(function (int $candidateId) use ($rowsByCandidate, $judges, $factorCount): array {
            $byJudge = $rowsByCandidate->get($candidateId, collect())->keyBy('judge_id');

            $breakdown = $judges->map(function (RoomJudge $judge) use ($byJudge, $factorCount): array {
                $row = $byJudge->get($judge->id);

                return [
                    'judge' => $judge,
                    'enteredCount' => $row !== null ? (int) $row->cnt : 0,
                    'factorCount' => $factorCount,
                    'total' => $row !== null ? (int) $row->total : 0,
                ];
            });

            return [$candidateId => $breakdown];
        })->all();
    }

    private function judgeTypeRank(string $judgeType): int
    {
        $values = array_map(fn (JudgeType $case): string => $case->value, JudgeType::cases());
        $rank = array_search($judgeType, $values, true);

        return $rank === false ? 0 : $rank;
    }
}
