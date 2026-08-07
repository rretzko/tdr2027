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
use App\Models\Version;
use App\Models\VersionRoom;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query/business logic for the judge Adjudication page (Adjudicate.php).
 * Candidate<->Room membership is derived live from voice part — no manual
 * assignment table exists or is planned; a Candidate belongs to a Room iff
 * its voice_part_id is among that Room's configured voiceParts() and its
 * status is Registered (see Adjudication Structure.docx).
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
            ->where('status', CandidateStatus::Registered)
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

        $max = RoomJudge::where('room_id', $room->id)->count() * $this->factorCount($room);

        $counts = Score::where('version_id', $room->version_id)
            ->whereIn('candidate_id', $candidateIds)
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
     * @return Collection<int|string, EloquentCollection<int, Score>>
     */
    public function scoresForCandidate(Version $version, Candidate $candidate): Collection
    {
        return Score::where('version_id', $version->id)
            ->where('candidate_id', $candidate->id)
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
    public function saveScores(RoomJudge $judge, Candidate $candidate, Version $version, array $scoresByFactorId): void
    {
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
                    ],
                );
            }
        }
    }

    private function judgeTypeRank(string $judgeType): int
    {
        $values = array_map(fn (JudgeType $case): string => $case->value, JudgeType::cases());
        $rank = array_search($judgeType, $values, true);

        return $rank === false ? 0 : $rank;
    }
}
