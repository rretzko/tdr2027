<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\VersionDateType;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Support\Adjudication\JudgeSave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live adjudication progress for the Scorecard's Adjudication tab: where
 * every Room and judge stands right now, and roughly how much work is left.
 *
 * Timing is derived from scores timestamps alone. Adjudicate::save() writes
 * a judge's whole rubric for one Candidate in a single request, so the
 * earliest created_at of a (judge, candidate) pair is one "save event", and
 * the gap between a judge's consecutive save events approximates the time
 * spent on a Candidate. A gap longer than SITTING_BREAK_SECONDS starts a new
 * sitting; only in-sitting gaps feed the pace.
 */
final class AdjudicationProgressService
{
    public const SITTING_BREAK_SECONDS = 1200;

    /** A judge with less than this share of their Room scored has only sampled it. */
    public const JUST_STARTED_RATIO = 0.10;

    /** A started-but-unfinished judge quiet for this long is flagged idle. */
    public const IDLE_HOURS = 48;

    /** In-sitting gaps needed before a judge's own pace is trusted over the Version's. */
    private const MIN_PACE_SAMPLES = 3;

    public function __construct(private AdjudicationService $adjudication) {}

    /**
     * @return array{
     *     window: array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int},
     *     totals: array{expected: int, completed: int, percent: int, candidates: int, candidatesComplete: int, judges: int, judgesComplete: int, judgesNotStarted: int, judgesNeedingAttention: int, paceSeconds: ?int, remainingMinutes: ?int},
     *     rooms: list<array{room: VersionRoom, candidates: int, candidatesComplete: int, expected: int, completed: int, percent: int, judges: list<array<string, mixed>>}>,
     *     daily: list<array{date: string, x: string, heading: string, saves: int}>,
     * }
     */
    public function forVersion(Version $version, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $window = $this->window($version, $now);

        $rooms = VersionRoom::where('version_id', $version->id)
            ->with(['voiceParts', 'roomJudges.user'])
            ->orderBy('order_by')
            ->orderBy('id')
            ->get();

        $pairs = $this->savePairs($version);
        $versionPace = $this->medianPace($pairs);

        $roomRows = $rooms->map(fn (VersionRoom $room): array => $this->roomRow($room, $pairs, $versionPace, $now))->values();

        $judgeRows = $roomRows->flatMap(fn (array $room): array => $room['judges'])->filter(fn (array $judge): bool => $judge['scoring']);
        $expected = (int) $roomRows->sum('expected');
        $completed = (int) $roomRows->sum('completed');
        $remainingMinutes = $judgeRows->sum(fn (array $judge): int => $judge['remainingMinutes'] ?? 0);

        return [
            'window' => $window,
            'totals' => [
                'expected' => $expected,
                'completed' => $completed,
                'percent' => $this->percent($completed, $expected),
                'candidates' => (int) $roomRows->sum('candidates'),
                'candidatesComplete' => (int) $roomRows->sum('candidatesComplete'),
                'judges' => $judgeRows->count(),
                'judgesComplete' => $judgeRows->where('status', 'complete')->count(),
                'judgesNotStarted' => $judgeRows->where('status', 'not_started')->count(),
                'judgesNeedingAttention' => $judgeRows->filter(fn (array $judge): bool => $judge['needsAttention'])->count(),
                'paceSeconds' => $versionPace,
                'remainingMinutes' => $versionPace === null ? null : (int) $remainingMinutes,
            ],
            'rooms' => $roomRows->all(),
            'daily' => $this->daily($pairs, $window),
        ];
    }

    /**
     * @return array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int}
     */
    private function window(Version $version, Carbon $now): array
    {
        $date = VersionDate::where('version_id', $version->id)
            ->where('date_type', VersionDateType::Adjudication)
            ->first();

        $start = $date?->start_at === null ? null : Carbon::parse($date->getRawOriginal('start_at'));
        $end = $date?->end_at === null ? null : Carbon::parse($date->getRawOriginal('end_at'));

        $status = match (true) {
            $start === null => 'unscheduled',
            $now->lt($start) => 'upcoming',
            $end !== null && $now->gt($end) => 'closed',
            default => 'open',
        };

        $daysLeft = $end === null || $status === 'closed' ? null : (int) ceil($now->diffInHours($end) / 24);

        return ['start' => $start, 'end' => $end, 'status' => $status, 'daysLeft' => $daysLeft];
    }

    /**
     * One row per (judge, candidate) with any score in this Version.
     *
     * @return Collection<int, JudgeSave>
     */
    private function savePairs(Version $version): Collection
    {
        return Score::where('version_id', $version->id)
            ->select(
                'judge_id',
                'candidate_id',
                DB::raw('count(*) as cnt'),
                DB::raw('min(created_at) as first_at'),
                DB::raw('max(updated_at) as last_at'),
            )
            ->groupBy('judge_id', 'candidate_id')
            ->toBase()
            ->get()
            ->map(fn (object $row): JudgeSave => new JudgeSave(
                judgeId: (int) $row->judge_id,
                candidateId: (int) $row->candidate_id,
                factorsEntered: (int) $row->cnt,
                firstAt: Carbon::parse($row->first_at),
                lastAt: Carbon::parse($row->last_at),
            ));
    }

    /**
     * @param  Collection<int, JudgeSave>  $pairs
     * @return array{room: VersionRoom, candidates: int, candidatesComplete: int, expected: int, completed: int, percent: int, judges: list<array<string, mixed>>}
     */
    private function roomRow(VersionRoom $room, Collection $pairs, ?int $versionPace, Carbon $now): array
    {
        $candidateIds = $this->adjudication->candidatesForRoom($room)->pluck('id')->flip();
        $factorCount = $this->adjudication->factorCount($room);
        $candidateCount = $candidateIds->count();

        $judges = $room->roomJudges
            ->sortBy(fn (RoomJudge $judge): int => $this->judgeTypeRank($judge->getRawOriginal('judge_type')))
            ->values()
            ->map(fn (RoomJudge $judge): array => $this->judgeRow(
                $judge,
                $pairs->where('judgeId', $judge->id)->filter(fn (JudgeSave $pair): bool => $candidateIds->has($pair->candidateId))->values(),
                $candidateCount,
                $factorCount,
                $versionPace,
                $now,
            ));

        $scoring = $judges->filter(fn (array $judge): bool => $judge['scoring']);

        // A Candidate is complete in this Room once every scoring judge has
        // entered the full rubric for them.
        $completeByCandidate = $scoring
            ->flatMap(fn (array $judge): array => $judge['completedCandidateIds'])
            ->countBy();
        $candidatesComplete = $scoring->isEmpty()
            ? 0
            : $completeByCandidate->filter(fn (int $count): bool => $count === $scoring->count())->count();

        $expected = $candidateCount * $scoring->count();
        $completed = (int) $scoring->sum('completed');

        return [
            'room' => $room,
            'candidates' => $candidateCount,
            'candidatesComplete' => $candidatesComplete,
            'expected' => $expected,
            'completed' => $completed,
            'percent' => $this->percent($completed, $expected),
            'judges' => $judges->map(function (array $judge): array {
                unset($judge['completedCandidateIds']);

                return $judge;
            })->all(),
        ];
    }

    /**
     * @param  Collection<int, JudgeSave>  $pairs
     * @return array<string, mixed>
     */
    private function judgeRow(RoomJudge $judge, Collection $pairs, int $candidateCount, int $factorCount, ?int $versionPace, Carbon $now): array
    {
        $judgeType = JudgeType::from($judge->getRawOriginal('judge_type'));
        $judgeStatus = JudgeStatus::from($judge->getRawOriginal('status'));

        // Monitors don't score, and an exempted judge isn't expected to.
        $scoring = $judgeType !== JudgeType::Monitor && $judgeStatus !== JudgeStatus::Exempt;

        $completedPairs = $factorCount === 0 ? collect() : $pairs->filter(fn (JudgeSave $pair): bool => $pair->factorsEntered >= $factorCount);
        $completed = $completedPairs->count();
        $partial = $pairs->count() - $completed;

        $saves = $pairs->pluck('firstAt')->sort()->values();
        $gaps = $this->gaps($saves);
        $inSitting = $gaps->filter(fn (int $gap): bool => $gap <= self::SITTING_BREAK_SECONDS)->values();
        $sittings = $saves->isEmpty() ? 0 : $gaps->filter(fn (int $gap): bool => $gap > self::SITTING_BREAK_SECONDS)->count() + 1;

        $ownPace = $inSitting->count() >= self::MIN_PACE_SAMPLES ? $this->median($inSitting->all()) : null;
        $pace = $ownPace ?? $versionPace;
        $remaining = max(0, $candidateCount - $completed);
        $lastActivity = $pairs->max('lastAt');

        $status = match (true) {
            ! $scoring => 'not_scoring',
            $candidateCount === 0 => 'no_candidates',
            $remaining === 0 => 'complete',
            $pairs->isEmpty() => 'not_started',
            $completed < $candidateCount * self::JUST_STARTED_RATIO => 'just_started',
            default => 'in_progress',
        };

        $idle = in_array($status, ['just_started', 'in_progress'], true)
            && $lastActivity instanceof Carbon
            && $lastActivity->lt($now->copy()->subHours(self::IDLE_HOURS));

        return [
            'judge' => $judge,
            'name' => $judge->user->name,
            'type' => $judgeType->label(),
            'scoring' => $scoring,
            'status' => $status,
            'idle' => $idle,
            'needsAttention' => $scoring && ($status === 'not_started' || $status === 'just_started' || $idle),
            'completed' => $completed,
            'partial' => $partial,
            'remaining' => $scoring ? $remaining : 0,
            'percent' => $this->percent($completed, $candidateCount),
            'sittings' => $sittings,
            'paceSeconds' => $ownPace,
            'paceIsEstimate' => $ownPace === null,
            'remainingMinutes' => $scoring && $pace !== null && $remaining > 0 ? (int) ceil($remaining * $pace / 60) : null,
            'firstActivity' => $saves->first(),
            'lastActivity' => $lastActivity,
            'completedCandidateIds' => $completedPairs->pluck('candidateId')->all(),
        ];
    }

    /**
     * Median in-sitting gap across every judge in the Version — the fallback
     * pace for judges who haven't scored enough to have their own.
     *
     * @param  Collection<int, JudgeSave>  $pairs
     */
    private function medianPace(Collection $pairs): ?int
    {
        $gaps = $pairs->groupBy('judgeId')
            ->flatMap(fn (Collection $judgePairs): Collection => $this->gaps($judgePairs->pluck('firstAt')->sort()->values()))
            ->filter(fn (int $gap): bool => $gap <= self::SITTING_BREAK_SECONDS)
            ->values();

        return $gaps->count() >= self::MIN_PACE_SAMPLES ? $this->median($gaps->all()) : null;
    }

    /**
     * Candidates saved per display-timezone day, spanning the adjudication
     * window (or just the days with activity when no window is set).
     *
     * @param  Collection<int, JudgeSave>  $pairs
     * @param  array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int}  $window
     * @return list<array{date: string, x: string, heading: string, saves: int}>
     */
    private function daily(Collection $pairs, array $window): array
    {
        $byDay = $pairs->countBy(fn (JudgeSave $pair): string => $pair->firstAt->copy()->forDisplay()->toDateString());

        $days = $byDay->keys();
        if ($window['start'] !== null) {
            $days = $days->push($window['start']->copy()->forDisplay()->toDateString());
        }
        if ($window['end'] !== null) {
            $days = $days->push($window['end']->copy()->forDisplay()->toDateString());
        }

        if ($days->isEmpty()) {
            return [];
        }

        $sorted = $days->unique()->sort()->values();
        $rows = [];

        for ($day = Carbon::parse($sorted->first()); $day->lte(Carbon::parse($sorted->last())); $day->addDay()) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                'x' => $day->format('D j'),
                'heading' => $day->format('l, M j'),
                'saves' => (int) ($byDay[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Carbon>  $saves  ascending
     * @return Collection<int, int> seconds between consecutive saves
     */
    private function gaps(Collection $saves): Collection
    {
        return $saves->sliding(2)->map(fn (Collection $pair): int => (int) $pair->first()->diffInSeconds($pair->last()))->values();
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        return (int) round((float) collect($values)->median());
    }

    private function percent(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : (int) floor($part * 100 / $whole);
    }

    private function judgeTypeRank(string $judgeType): int
    {
        $values = array_map(fn (JudgeType $case): string => $case->value, JudgeType::cases());
        $rank = array_search($judgeType, $values, true);

        return $rank === false ? 0 : $rank;
    }
}
