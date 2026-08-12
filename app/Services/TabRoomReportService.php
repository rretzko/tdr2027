<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Score;
use App\Models\Version;
use App\Models\VoicePart;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Row-data queries for the Tab Room Reports sub-module (Tab Room
 * Module.docx §"Reports"): Audition Scores, Combined Audition Scores
 * (confidential/public), Ensemble Participation, Student Seniority. Reads
 * `scores` directly rather than resolving Room/rubric membership — every
 * Score row already carries its own frozen `judge_order_by`/
 * `score_factor_order_by`/`voice_part_id` snapshot (see Score's own
 * docblock), so a report scoped to one Voice Part needs no Room lookup at
 * all, unlike AdjudicationService's live-scoring-page queries which must
 * scope through a Room because more than one Room can share a judge pool.
 *
 * The three per-Voice-Part/per-Ensemble entry points (auditionScoreRows(),
 * ensembleParticipationRows(), studentSeniorityRows()) are each wrapped in
 * TabRoomReportCache::remember() — these reports are static once Ensemble
 * Cut-offs are established for a Voice Part, per the product owner (2026-08-
 * 11): the only two things that can still change one are a Score edit
 * (AdjudicationService::saveScores()) or a cutoff decision changing
 * (EnsembleCutoffService's applyCutoff()/applyEnsembleCutoff()/
 * finalizeVoicePart()), both of which call TabRoomReportCache::forget() on
 * write. combinedScoreRows()/allEnsemblesScoreRows() need no caching of
 * their own — they're thin loops over the now-cached auditionScoreRows(),
 * so a cache hit there already avoids the real cost (the DB queries), not
 * the loop.
 */
final class TabRoomReportService
{
    /**
     * Every Candidate at this Voice Part in a reportable state (still
     * awaiting a decision, or one of the four resolved outcomes — same set
     * AdjudicationService::candidatesForRoom() tracks), with one column per
     * distinct (judge, factor) pair actually scored for this Voice Part
     * (headed by the factor's abbreviation alone, not the judge's name —
     * judges are anonymous to a report reader, same as everywhere else
     * scores are shown), grouped under two header rows — `categoryGroups`
     * (each Score Category's `description`) and, nested under it,
     * `judgeGroups` (each judge's `judge_type` label, e.g. "Head Judge") —
     * that Candidate's grand total (AdjudicationService::
     * versionCandidateTotal()), and a "result" column (see resultLabel()):
     * the accepted Ensemble's abbreviation, "na", "inc", "ns", or "err".
     * Rows sort by resultRank() first (complete auditions — Accepted/Not
     * Accepted — as one block, then Incomplete, then No Show, then Error —
     * "err" also covers a still-Registered Candidate awaiting a cutoff
     * decision, not just a genuinely unexpected status), and only within a
     * rank by total, best-to-worst per $version->score_order. The
     * Incomplete/No Show ranks are only reachable for in-person auditions —
     * moot for remote, where a Candidate is always Registered/Accepted/
     * NotAccepted — kept here for completeness. Columns sort
     * by Score Category, then judge_type (room_judges.judge_type's own
     * declaration-order rank, i.e. judge_order_by), then the factor's own
     * abbreviation alphabetically — grouping requires every column under
     * one category, and every column under one judge within that category,
     * to be contiguous. Two nested readability borders the view draws
     * around column spans, plus alternating shading: every Score Category
     * is flagged `box`/`category_box` (via `is_category_start`/
     * `is_category_end` on `columns` and `judgeGroups`) — always on, for
     * consistency with the judge-level box below, not just every other one
     * — and every *even-numbered* (2nd/4th/6th…) category is separately
     * flagged `shaded`/`category_shaded`, a background tint the view
     * applies to that category's whole column span instead of a second
     * border style. A finer, always-on second box layer nests inside every
     * category: each judge_type section within it gets its own box too,
     * via `is_judge_start`/`is_judge_end` on `columns` (a judge group is
     * one cell in `judgeGroups`, so it never needs its own start/end — the
     * whole cell is the box).
     *
     * @return array{columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}
     */
    public function auditionScoreRows(Version $version, VoicePart $voicePart, EnsembleCutoffService $cutoffs): array
    {
        $cached = TabRoomReportCache::remember(
            $version,
            "audition_score_rows:{$voicePart->id}",
            function () use ($version, $voicePart, $cutoffs): array {
                $data = $this->computeAuditionScoreRows($version, $voicePart, $cutoffs);

                return [
                    'columns' => $data['columns']->all(),
                    'categoryGroups' => $data['categoryGroups']->all(),
                    'judgeGroups' => $data['judgeGroups']->all(),
                    'rows' => $data['rows']->all(),
                ];
            },
        );

        return [
            'columns' => collect($cached['columns']),
            'categoryGroups' => collect($cached['categoryGroups']),
            'judgeGroups' => collect($cached['judgeGroups']),
            'rows' => $this->hydrateCandidateRows(collect($cached['rows'])),
        ];
    }

    /**
     * Re-attaches live Candidate models onto cached rows keyed by
     * candidate_id — cached report data never stores Eloquent models *or
     * Collections* directly, only plain arrays/scalars. This app's
     * config/cache.php sets `serializable_classes => false`, which makes
     * every cache store call `unserialize($value, ['allowed_classes' =>
     * false])` — that rejects *every* object on the way back out, not just
     * Eloquent models, so a cached Collection comes back as a
     * __PHP_Incomplete_Class too. The test suite's 'array' cache store
     * never serializes at all, so this class of bug has no coverage without
     * deliberately forcing a real store. Confirmed in production
     * (2026-08-11): a cache-hit Candidate came back incomplete, crashing
     * the very first method call on it. One extra indexed WHERE-IN(id)
     * query per read (cache hit or miss) is a small price for never
     * touching that failure mode again.
     *
     * @param  Collection<int, array{candidate_id: int, scores: array<string, int>, total: int, result: string}>  $rows
     * @return Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>
     */
    private function hydrateCandidateRows(Collection $rows): Collection
    {
        $candidatesById = Candidate::whereIn('id', $rows->pluck('candidate_id'))->with('voicePart')->get()->keyBy('id');

        // Dropping (not erroring on) a row whose Candidate no longer exists
        // is also just correct behavior — a deleted Candidate between the
        // cache write and this read shouldn't appear in the report.
        return $rows
            ->map(function (array $row) use ($candidatesById): ?array {
                $candidate = $candidatesById->get($row['candidate_id']);

                return $candidate === null ? null : [
                    'candidate' => $candidate,
                    'scores' => $row['scores'],
                    'total' => $row['total'],
                    'result' => $row['result'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array{columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate_id: int, scores: array<string, int>, total: int, result: string}>}
     */
    private function computeAuditionScoreRows(Version $version, VoicePart $voicePart, EnsembleCutoffService $cutoffs): array
    {
        $candidates = Candidate::where('version_id', $version->id)
            ->where('voice_part_id', $voicePart->id)
            ->whereIn('status', CandidateStatus::roomTrackingStates())
            ->with('acceptedEnsemble')
            ->get();

        $scores = Score::where('version_id', $version->id)
            ->where('voice_part_id', $voicePart->id)
            ->whereIn('candidate_id', $candidates->pluck('id'))
            ->with(['scoreFactor', 'scoreCategory', 'judge'])
            ->get();

        $orderedScores = $scores
            ->unique(fn (Score $score): string => "{$score->judge_id}:{$score->score_factor_id}")
            ->sortBy(fn (Score $score): array => [$score->score_category_order_by, $score->judge_order_by, $score->scoreFactor->abbreviation])
            ->values();

        $rawColumns = $orderedScores->map(fn (Score $score): array => [
            'judge_id' => $score->judge_id,
            'score_factor_id' => $score->score_factor_id,
            'score_category_id' => $score->score_category_id,
            'label' => $score->scoreFactor->abbreviation,
        ])->values();

        // Category is the primary sort key, so every same-category column is
        // guaranteed contiguous — a plain run-length pass keyed on
        // score_category_id alone is enough here. Every category is boxed
        // (consistent with the always-on judge box below); every other,
        // *even*-numbered (0-based odd index → 2nd/4th/6th…) category is
        // separately flagged `shaded`, the alternating-tint readability aid.
        $categoryGroups = $this->runLengthGroups(
            $orderedScores,
            fn (Score $score): int => $score->score_category_id,
            fn (Score $score): string => (string) $score->scoreCategory->description,
        )->values()->map(fn (array $group, int $index): array => [...$group, 'box' => true, 'shaded' => $index % 2 === 1])->values();

        // Judge is only the *second* sort key — the same judge can recur in
        // a later, non-adjacent category block (e.g. Head Judge scores both
        // Scales and Solo), so the run-length key must include the category
        // too, or two separate judge blocks would wrongly merge into one.
        $rawJudgeGroups = $this->runLengthGroups(
            $orderedScores,
            fn (Score $score): string => "{$score->score_category_id}:{$score->judge_id}",
            fn (Score $score): string => JudgeType::from($score->judge->getRawOriginal('judge_type'))->label(),
        )->values();

        // Expand both categoryGroups' and judgeGroups' spans back onto
        // individual columns via fresh index => flags lookups (not in-place
        // mutation of $rawColumns' items — PHPStan can't track a Collection
        // item mutated key-by-key through nested offsetSet calls, only a
        // value rebuilt whole in one map() pass), then reuse the per-column
        // category flags to derive each judgeGroup's own outer-box edge
        // status below, since a judge group never crosses a category
        // boundary (the run-length key includes it).
        $columnCategoryFlags = [];
        $offset = 0;
        foreach ($categoryGroups as $group) {
            for ($i = 0; $i < $group['span']; $i++) {
                $columnCategoryFlags[$offset + $i] = [
                    'category_box' => $group['box'],
                    'category_shaded' => $group['shaded'],
                    'is_category_start' => $i === 0,
                    'is_category_end' => $i === $group['span'] - 1,
                ];
            }
            $offset += $group['span'];
        }

        $columnJudgeFlags = [];
        $offset = 0;
        foreach ($rawJudgeGroups as $group) {
            for ($i = 0; $i < $group['span']; $i++) {
                $columnJudgeFlags[$offset + $i] = [
                    'is_judge_start' => $i === 0,
                    'is_judge_end' => $i === $group['span'] - 1,
                ];
            }
            $offset += $group['span'];
        }

        $columns = $rawColumns->values()->map(fn (array $column, int $index): array => [...$column, ...$columnCategoryFlags[$index], ...$columnJudgeFlags[$index]]);

        $offset = 0;
        $judgeGroups = $rawJudgeGroups->map(function (array $group) use ($columnCategoryFlags, &$offset): array {
            $firstColumnFlags = $columnCategoryFlags[$offset];
            $lastColumnFlags = $columnCategoryFlags[$offset + $group['span'] - 1];
            $enriched = [
                ...$group,
                'box' => $firstColumnFlags['category_box'],
                'shaded' => $firstColumnFlags['category_shaded'],
                'is_category_start' => $firstColumnFlags['is_category_start'],
                'is_category_end' => $lastColumnFlags['is_category_end'],
            ];
            $offset += $group['span'];

            return $enriched;
        });

        $scoresByCandidate = $scores->groupBy('candidate_id');
        $totals = $cutoffs->voicePartTotals($version, $voicePart, $candidates->pluck('id'));

        $rows = $candidates->map(function (Candidate $candidate) use ($scoresByCandidate, $totals): array {
            $candidateScores = $scoresByCandidate->get($candidate->id, collect())
                ->mapWithKeys(fn (Score $score): array => ["{$score->judge_id}:{$score->score_factor_id}" => $score->score]);

            return [
                'candidate_id' => $candidate->id,
                'scores' => $candidateScores->all(),
                'total' => $totals[$candidate->id] ?? 0,
                'result' => $this->resultLabel($candidate),
                'resultRank' => $this->resultRank($candidate),
            ];
        });

        // Complete auditions (Accepted/Not Accepted) sort best-to-worst by
        // score first; Incomplete, then No Show, then any other condition
        // (Error) each follow as their own block after — resultRank is the
        // primary key, total only breaks ties within a block.
        $sortDirection = $version->getRawOriginal('score_order') === 'desc' ? 'desc' : 'asc';
        $rows = $rows
            ->sort(function (array $a, array $b) use ($sortDirection): int {
                if ($a['resultRank'] !== $b['resultRank']) {
                    return $a['resultRank'] <=> $b['resultRank'];
                }

                return $sortDirection === 'desc' ? $b['total'] <=> $a['total'] : $a['total'] <=> $b['total'];
            })
            ->map(fn (array $row): array => ['candidate_id' => $row['candidate_id'], 'scores' => $row['scores'], 'total' => $row['total'], 'result' => $row['result']])
            ->values();

        return ['columns' => $columns, 'categoryGroups' => $categoryGroups, 'judgeGroups' => $judgeGroups, 'rows' => $rows];
    }

    /**
     * The Result column's display value: the accepted Ensemble's
     * abbreviation (not the generic "Accepted" label — a reader needs to
     * know *which* Ensemble), "na" for Not Accepted, "inc"/"ns" for the two
     * incomplete-audition outcomes (only reachable for in-person auditions
     * — moot for remote, where every Candidate in `roomTrackingStates()` is
     * necessarily Registered/Accepted/NotAccepted), and "err" for any other
     * condition (including a still-undecided Registered Candidate, or an
     * Accepted Candidate somehow missing its accepted_ensemble_id).
     */
    private function resultLabel(Candidate $candidate): string
    {
        return match (CandidateStatus::from($candidate->getRawOriginal('status'))) {
            // Not `?->` — Larastan flags it nullsafe.neverNull here even
            // though accepted_ensemble_id is genuinely nullable at the DB
            // level (cataloged Larastan quirk; see PHPStan-quirks memory).
            CandidateStatus::Accepted => $candidate->acceptedEnsemble !== null ? $candidate->acceptedEnsemble->abbreviation : 'err',
            CandidateStatus::NotAccepted => 'na',
            CandidateStatus::Incomplete => 'inc',
            CandidateStatus::NoShow => 'ns',
            default => 'err',
        };
    }

    /**
     * The Result column's sort bucket: complete auditions (Accepted/Not
     * Accepted, sorted by score within this rank) first, then Incomplete,
     * then No Show, then Error last.
     */
    private function resultRank(Candidate $candidate): int
    {
        return match (CandidateStatus::from($candidate->getRawOriginal('status'))) {
            CandidateStatus::Accepted, CandidateStatus::NotAccepted => 0,
            CandidateStatus::Incomplete => 1,
            CandidateStatus::NoShow => 2,
            default => 3,
        };
    }

    /**
     * Collapses an already-ordered list into contiguous runs of matching
     * $keyFn() values, each becoming one spanning header cell — unlike
     * Collection::groupBy(), which buckets by key regardless of whether
     * matching items are actually adjacent, this treats two non-adjacent
     * runs sharing the same key (e.g. the same judge scoring two different,
     * non-adjacent Score Categories) as two separate groups, matching what
     * a colspan header must actually render.
     *
     * @template TKey
     *
     * @param  Collection<int, Score>  $orderedScores
     * @param  callable(Score): TKey  $keyFn
     * @param  callable(Score): string  $labelFn
     * @return Collection<int, array{label: string, span: int}>
     */
    private function runLengthGroups(Collection $orderedScores, callable $keyFn, callable $labelFn): Collection
    {
        $groups = collect();
        $currentKey = null;
        $currentLabel = '';
        $span = 0;

        foreach ($orderedScores as $score) {
            $key = $keyFn($score);

            if ($span > 0 && $key !== $currentKey) {
                $groups->push(['label' => $currentLabel, 'span' => $span]);
                $span = 0;
            }

            if ($span === 0) {
                $currentKey = $key;
                $currentLabel = $labelFn($score);
            }

            $span++;
        }

        if ($span > 0) {
            $groups->push(['label' => $currentLabel, 'span' => $span]);
        }

        return $groups;
    }

    /**
     * The same per-row shape as auditionScoreRows(), one entry per Voice
     * Part belonging to $ensemble — the "Combined" report is every Voice
     * Part's own scores table, not one flattened table (rubrics/judges
     * differ per Voice Part, so the columns aren't uniform across parts).
     * Every row also carries the identity fields (student, school, teacher)
     * the confidential variant renders and the public variant omits — built
     * once here since the query is identical either way; only the view/
     * export layer's column projection differs. Rows are scoped to
     * Candidates actually accepted into $ensemble (see voicePartTables()) —
     * a shared Voice Part's full audition pool (registered/not-accepted/
     * accepted-elsewhere included) is what the "All Ensembles" filter
     * (allEnsemblesScoreRows()) is for instead.
     *
     * @return Collection<int, array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}>
     */
    public function combinedScoreRows(Version $version, Ensemble $ensemble, EnsembleCutoffService $cutoffs): Collection
    {
        return $this->voicePartTables($version, $ensemble->voiceParts, $cutoffs, acceptedOnly: true, ensemble: $ensemble);
    }

    /**
     * Every Voice Part reachable from any Ensemble on the Version, each
     * rendered exactly once — the Combined Audition Scores reports' "All"
     * Ensemble filter option, so a Tab Room Manager can pull one export
     * covering the whole roster rather than one Ensemble at a time. Unlike
     * combinedScoreRows(), rows are *not* scoped to one Ensemble's accepted
     * Candidates: "All" is the full audition pool for every Voice Part,
     * decision or no decision — accepted into any Ensemble, not accepted,
     * incomplete, or no-show. Per the product owner (2026-08-11), that
     * roster is inherently a mix of outcomes/Ensembles (including "no
     * Ensemble at all" for the not-accepted), so an Ensemble only belongs
     * on a Candidate's own row, via the Result column — there's no
     * meaningful per-Ensemble *grouping* of this data, which is why (unlike
     * an earlier version of this method) a Voice Part shared by more than
     * one Ensemble is de-duplicated to a single table rather than repeated
     * once per Ensemble.
     *
     * @return Collection<int, array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}>
     */
    public function allEnsemblesScoreRows(Version $version, EnsembleCutoffService $cutoffs): Collection
    {
        $voiceParts = $version->ensembleOrder
            ->map(fn ($order) => $order->ensemble)
            ->flatMap(fn (Ensemble $ensemble): Collection => $ensemble->voiceParts)
            ->unique('id')
            ->values();

        return $this->voicePartTables($version, $voiceParts, $cutoffs, acceptedOnly: false);
    }

    /**
     * @param  Collection<int, VoicePart>  $voiceParts
     * @return Collection<int, array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}>
     */
    private function voicePartTables(Version $version, Collection $voiceParts, EnsembleCutoffService $cutoffs, bool $acceptedOnly, ?Ensemble $ensemble = null): Collection
    {
        return $voiceParts->map(function (VoicePart $voicePart) use ($version, $ensemble, $cutoffs, $acceptedOnly): array {
            $data = $this->auditionScoreRows($version, $voicePart, $cutoffs);

            if ($acceptedOnly && $ensemble !== null) {
                $data['rows'] = $data['rows']->filter(
                    fn (array $row): bool => CandidateStatus::from($row['candidate']->getRawOriginal('status')) === CandidateStatus::Accepted
                        && $row['candidate']->accepted_ensemble_id === $ensemble->id,
                )->values();
            }

            // Not ->each(fn ($row) => $row['candidate']->loadMissing(...)) —
            // loadMissing() called per-model (rather than once on an
            // Eloquent Collection of them) re-runs one query per relation
            // *per Candidate* instead of one batched WHERE-IN per relation
            // for the whole table. At "All Ensembles" scale (every Voice
            // Part's full pool, unfiltered) that N+1 was enough to blow the
            // 30s PDF execution limit.
            EloquentCollection::make($data['rows']->pluck('candidate'))
                ->loadMissing(['student.user', 'school', 'teacher.user']);

            return ['voicePart' => $voicePart, ...$data];
        });
    }

    /**
     * The public-report table shape (auditionScoreRows()'s per-Voice-Part
     * grid), narrowed to a single row for one Candidate — backs the
     * Registrations-side Per-School and Per-Person score reports (Results
     * page), both of which render one Candidate per page/section rather
     * than a shared multi-candidate table. Reuses the cached
     * auditionScoreRows() call for that Candidate's own Voice Part rather
     * than a bespoke query, same "thin loop over the cached per-Voice-Part
     * data" approach combinedScoreRows()/allEnsemblesScoreRows() already
     * use. Returns null if this Candidate has no row yet (not in
     * auditionScoreRows()'s CandidateStatus::roomTrackingStates() pool, or
     * simply not scored) — the caller (a Results-page controller/component)
     * treats that as "nothing to show" rather than an error.
     *
     * @return array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}|null
     */
    public function candidateScoreRow(Version $version, Candidate $candidate, EnsembleCutoffService $cutoffs): ?array
    {
        $data = $this->auditionScoreRows($version, $candidate->voicePart, $cutoffs);

        $row = $data['rows']->first(fn (array $row): bool => $row['candidate']->id === $candidate->id);

        if ($row === null) {
            return null;
        }

        EloquentCollection::make([$row['candidate']])->loadMissing(['student.user', 'school', 'teacher.user']);

        return ['voicePart' => $candidate->voicePart, ...$data, 'rows' => collect([$row])];
    }

    /**
     * Every Candidate accepted into $ensemble, with student contact info,
     * school, teacher, emergency contact, voice part, grand total, and
     * grade/class_of — Tab Room Module.docx's Ensemble Participation report
     * columns.
     *
     * @return Collection<int, array{candidate: Candidate, total: int}>
     */
    public function ensembleParticipationRows(Version $version, Ensemble $ensemble, EnsembleCutoffService $cutoffs): Collection
    {
        $cached = collect(TabRoomReportCache::remember(
            $version,
            "ensemble_participation_rows:{$ensemble->id}",
            fn () => $this->computeEnsembleParticipationRows($version, $ensemble, $cutoffs)->all(),
        ));

        // Not cached directly — see hydrateCandidateRows()'s docblock.
        $candidatesById = Candidate::whereIn('id', $cached->pluck('candidate_id'))
            ->with(['student.user', 'school', 'teacher.user', 'emergencyContact', 'voicePart'])
            ->get()
            ->keyBy('id');

        return $cached
            ->map(function (array $row) use ($candidatesById): ?array {
                $candidate = $candidatesById->get($row['candidate_id']);

                return $candidate === null ? null : ['candidate' => $candidate, 'total' => $row['total']];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{candidate_id: int<0, max>, total: int}>
     */
    private function computeEnsembleParticipationRows(Version $version, Ensemble $ensemble, EnsembleCutoffService $cutoffs): Collection
    {
        $candidates = Candidate::where('version_id', $version->id)
            ->where('accepted_ensemble_id', $ensemble->id)
            ->where('status', CandidateStatus::Accepted)
            ->with(['student.user', 'voicePart'])
            ->get();

        // Batched per Voice Part (voicePartTotals() only ranks within one
        // Voice Part at a time) rather than one versionCandidateTotal() call
        // per Candidate, avoiding an N+1 across a multi-Voice-Part Ensemble.
        // Union (+), not flatMap/merge — both of those renumber/collapse
        // integer keys, which would silently discard the candidate_id => total
        // mapping voicePartTotals() returns.
        $totals = $candidates->groupBy('voice_part_id')
            ->reduce(fn (array $carry, Collection $group): array => $carry + $cutoffs->voicePartTotals($version, $group->first()->voicePart, $group->pluck('id')), []);

        return $candidates
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name))
            ->map(fn (Candidate $candidate): array => [
                'candidate_id' => $candidate->id,
                'total' => (int) ($totals[$candidate->id] ?? 0),
            ])
            ->values();
    }

    /**
     * For every Candidate currently accepted into $ensemble, their presence
     * (accepted or not) across every sibling Version of the same Event,
     * keyed by that Version's senior_class_of — a student who auditioned in
     * three prior years shows a 4-year grid with green/red per year. Built
     * directly here (no shared helper elsewhere needs the same cross-Version
     * join) rather than added to AdjudicationService, which is scoped to a
     * single Version throughout.
     *
     * @return Collection<int, array{candidate: Candidate, years: array<int, bool>}>
     */
    public function studentSeniorityRows(Version $version, Ensemble $ensemble): Collection
    {
        $cached = collect(TabRoomReportCache::remember(
            $version,
            "student_seniority_rows:{$ensemble->id}",
            fn () => $this->computeStudentSeniorityRows($version, $ensemble)->all(),
        ));

        // Not cached directly — see hydrateCandidateRows()'s docblock.
        $candidatesById = Candidate::whereIn('id', $cached->pluck('candidate_id'))
            ->with(['student.user', 'school', 'teacher.user', 'voicePart'])
            ->get()
            ->keyBy('id');

        return $cached
            ->map(function (array $row) use ($candidatesById): ?array {
                $candidate = $candidatesById->get($row['candidate_id']);

                return $candidate === null ? null : ['candidate' => $candidate, 'years' => $row['years']];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{candidate_id: int<0, max>, years: array<int<0, max>, bool>}>
     */
    private function computeStudentSeniorityRows(Version $version, Ensemble $ensemble): Collection
    {
        $siblingVersions = $version->event->versions()->orderBy('senior_class_of')->get();

        $current = Candidate::where('version_id', $version->id)
            ->where('accepted_ensemble_id', $ensemble->id)
            ->where('status', CandidateStatus::Accepted)
            ->with('student.user')
            ->get();

        $studentIds = $current->pluck('student_id');

        $acceptedByStudentAndVersion = Candidate::whereIn('version_id', $siblingVersions->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->where('status', CandidateStatus::Accepted)
            ->get()
            ->groupBy(fn (Candidate $candidate): string => "{$candidate->student_id}:{$candidate->version_id}");

        return $current
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name))
            ->map(function (Candidate $candidate) use ($siblingVersions, $acceptedByStudentAndVersion): array {
                $years = $siblingVersions->mapWithKeys(fn (Version $sibling): array => [
                    (int) $sibling->senior_class_of => $acceptedByStudentAndVersion->has("{$candidate->student_id}:{$sibling->id}"),
                ]);

                return ['candidate_id' => $candidate->id, 'years' => $years->all()];
            })
            ->values();
    }
}
