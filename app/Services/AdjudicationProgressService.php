<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdjudicationEventType;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\VersionDateType;
use App\Models\AdjudicationEvent;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Support\Adjudication\JudgeSave;
use App\Support\Adjudication\ListeningEstimate;
use App\Support\Adjudication\PlaybackEvent;
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

    /** A just-started judge who scored within this many hours is left alone — they're working. */
    public const RECENT_HOURS = 24;

    /** The recent scoring rate looks back at most this far... */
    public const RATE_WINDOW_HOURS = 72;

    /** ...and never over less than this, so a busy first hour doesn't project a sprint. */
    private const MIN_RATE_SPAN_HOURS = 24;

    /** A recent rate at or above this share of the needed rate is "at risk" rather than "behind". */
    public const AT_RISK_RATIO = 0.75;

    /** A save made before this share of the Candidate's recordings had played is a short listen. */
    public const SHORT_LISTEN_RATIO = 0.75;

    /** In-sitting gaps needed before a judge's own pace is trusted over the Version's. */
    private const MIN_PACE_SAMPLES = 3;

    public function __construct(private AdjudicationService $adjudication) {}

    /**
     * @return array{
     *     window: array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int},
     *     totals: array{expected: int, completed: int, percent: int, candidates: int, candidatesComplete: int, judges: int, judgesComplete: int, judgesNotStarted: int, judgesNeedingAttention: int, paceSeconds: ?int, remainingMinutes: ?int},
     *     schedule: array{status: string, remaining: int, neededPerDay: ?int, recentPerDay: ?int, rateDays: ?int, projectedFinish: ?Carbon},
     *     rooms: list<array{room: VersionRoom, candidates: int, candidatesComplete: int, expected: int, completed: int, percent: int, flag: ?string, judgesNotStarted: int, judges: list<array<string, mixed>>}>,
     *     listeningTracked: bool,
     *     daily: list<array{date: string, x: string, heading: string, saves: int}>,
     * }
     */
    public function forVersion(Version $version, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $window = $this->window($version, $now);

        $rooms = VersionRoom::where('version_id', $version->id)
            ->with(['voiceParts', 'roomJudges.user', 'scoreCategories.scoreFactors'])
            ->orderBy('order_by')
            ->orderBy('id')
            ->get();

        $pairs = $this->savePairs($version);
        $versionPace = $this->medianPace($pairs);
        $rateSince = $this->rateSince($window, $now);
        $playback = $this->playbackEvents($version, $pairs);
        $recordingLengths = $playback === [] ? [] : $this->recordingLengths($version);

        $roomRows = $rooms->map(fn (VersionRoom $room): array => $this->roomRow($room, $pairs, $playback, $recordingLengths, $versionPace, $now, $window['status'], $rateSince))->values();
        $completedSince = (int) $roomRows->sum('completedSince');
        $roomRows = $roomRows->map(function (array $room): array {
            unset($room['completedSince']);

            return $room;
        });

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
            'schedule' => $this->schedule($window, $expected, $expected - $completed, $completedSince, $rateSince, $now),
            'rooms' => $roomRows->all(),
            'listeningTracked' => $playback !== [],
            'daily' => $this->daily($pairs, $window),
        ];
    }

    /**
     * Start of the span the recent scoring rate is measured over: the last
     * RATE_WINDOW_HOURS, but no earlier than the window opening and never
     * shorter than MIN_RATE_SPAN_HOURS. Null when no projection applies.
     *
     * @param  array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int}  $window
     */
    private function rateSince(array $window, Carbon $now): ?Carbon
    {
        if ($window['status'] !== 'open' || $window['start'] === null || $window['end'] === null) {
            return null;
        }

        $since = $now->copy()->subHours(self::RATE_WINDOW_HOURS)->max($window['start']);

        return $since->min($now->copy()->subHours(self::MIN_RATE_SPAN_HOURS));
    }

    /**
     * Will the open window finish at the recent pace? Compares the judge
     * scorings needed per day until the window closes with the scorings
     * completed per day over the recent span.
     *
     * @param  array{start: ?Carbon, end: ?Carbon, status: string, daysLeft: ?int}  $window
     * @return array{status: string, remaining: int, neededPerDay: ?int, recentPerDay: ?int, rateDays: ?int, projectedFinish: ?Carbon}
     */
    private function schedule(array $window, int $expected, int $remaining, int $completedSince, ?Carbon $rateSince, Carbon $now): array
    {
        $empty = ['remaining' => $remaining, 'neededPerDay' => null, 'recentPerDay' => null, 'rateDays' => null, 'projectedFinish' => null];

        if ($expected > 0 && $remaining === 0) {
            return ['status' => 'complete', ...$empty];
        }

        if ($expected === 0 || $rateSince === null || $window['end'] === null) {
            return ['status' => 'not_applicable', ...$empty];
        }

        $daysLeft = max($now->diffInSeconds($window['end']) / 86400, 1 / 24);
        $spanDays = $rateSince->diffInSeconds($now) / 86400;
        $needed = $remaining / $daysLeft;
        $recent = $completedSince / $spanDays;

        $status = match (true) {
            $recent >= $needed => 'on_track',
            $recent >= $needed * self::AT_RISK_RATIO => 'at_risk',
            default => 'behind',
        };

        return [
            'status' => $status,
            'remaining' => $remaining,
            'neededPerDay' => (int) ceil($needed),
            'recentPerDay' => (int) round($recent),
            'rateDays' => max(1, (int) round($spanDays)),
            'projectedFinish' => $recent > 0 ? $now->copy()->addSeconds((int) ceil($remaining / $recent * 86400)) : null,
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
     * The Version's tracked Adjudicate-page activity, keyed "judgeId:candidateId".
     *
     * Only events up to a judge's first save on a Candidate can affect how
     * much they heard before scoring, so everything else — replays after
     * saving, review passes, Candidates not yet scored — is skipped before
     * any object is built. That filter runs here rather than as a SQL join
     * against scores: without an index on (room_judge_id, candidate_id,
     * created_at), MariaDB plans that join as a nested loop over each
     * judge's whole history and took ~10s on an all-state-sized Version.
     * Ordering by the (version_id, room_judge_id, created_at) index keeps
     * each pair's events chronological without a sort.
     *
     * @param  Collection<int, JudgeSave>  $pairs
     * @return array<string, list<PlaybackEvent>>
     */
    private function playbackEvents(Version $version, Collection $pairs): array
    {
        $firstSaves = [];
        foreach ($pairs as $pair) {
            $firstSaves[$pair->judgeId.':'.$pair->candidateId] = $pair->firstAt->getTimestamp();
        }

        if ($firstSaves === []) {
            return [];
        }

        $rows = AdjudicationEvent::where('version_id', $version->id)
            ->orderBy('room_judge_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->toBase()
            ->get(['room_judge_id', 'candidate_id', 'recording_id', 'event', 'position_seconds', 'created_at']);

        $events = [];
        foreach ($rows as $row) {
            $key = $row->room_judge_id.':'.$row->candidate_id;
            $at = strtotime((string) $row->created_at);

            if ($at === false || ! isset($firstSaves[$key]) || $at > $firstSaves[$key]) {
                continue;
            }

            $events[$key][] = new PlaybackEvent(
                event: AdjudicationEventType::from($row->event),
                recordingId: $row->recording_id === null ? null : (int) $row->recording_id,
                positionSeconds: $row->position_seconds === null ? null : (int) $row->position_seconds,
                at: $at,
            );
        }

        return $events;
    }

    /**
     * Known lengths of the Version's approved recordings, by Candidate, in
     * one query. Each Room keeps the ones whose file_type matches one of its
     * score categories — the same rule as AdjudicationService::recordingsForCandidate().
     *
     * @return array<int, list<array{id: int, type: string, seconds: int}>>
     */
    private function recordingLengths(Version $version): array
    {
        $rows = Recording::where('version_id', $version->id)
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_by')
            ->where('duration_seconds', '>', 0)
            ->toBase()
            ->get(['id', 'candidate_id', 'file_type', 'duration_seconds']);

        $lengths = [];
        foreach ($rows as $row) {
            $lengths[(int) $row->candidate_id][] = [
                'id' => (int) $row->id,
                'type' => strtolower(trim((string) $row->file_type)),
                'seconds' => (int) $row->duration_seconds,
            ];
        }

        return $lengths;
    }

    /**
     * @param  Collection<int, JudgeSave>  $pairs
     * @param  array<string, list<PlaybackEvent>>  $playback
     * @param  array<int, list<array{id: int, type: string, seconds: int}>>  $recordingLengths
     * @return array{room: VersionRoom, candidates: int, candidatesComplete: int, expected: int, completed: int, completedSince: int, percent: int, flag: ?string, judgesNotStarted: int, judges: list<array<string, mixed>>}
     */
    private function roomRow(VersionRoom $room, Collection $pairs, array $playback, array $recordingLengths, ?int $versionPace, Carbon $now, string $windowStatus, ?Carbon $rateSince): array
    {
        $candidateIds = $this->adjudication->candidatesForRoom($room)->pluck('id')->flip();

        // The rubric is eager-loaded with the Rooms; AdjudicationService::factorCount()
        // would query it again for every Room.
        $factorCount = (int) $room->scoreCategories->sum(fn (ScoreCategory $category): int => $category->scoreFactors->count());
        $categoryNames = $room->scoreCategories
            ->mapWithKeys(fn (ScoreCategory $category): array => [strtolower(trim($category->description)) => true])
            ->all();

        // recording id => length, for this Room's recordings of each Candidate.
        $durations = [];
        foreach ($candidateIds->keys() as $candidateId) {
            foreach ($recordingLengths[$candidateId] ?? [] as $recording) {
                if (isset($categoryNames[$recording['type']])) {
                    $durations[$candidateId][$recording['id']] = $recording['seconds'];
                }
            }
        }
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
                $rateSince,
                $playback,
                $durations,
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

        // Room-level stalls only matter once judges are expected to be working:
        // "stalled" = no scoring judge has begun; "uneven" = some have, but no
        // candidate can be fully scored until the rest do.
        $notStarted = $scoring->where('status', 'not_started')->count();
        $flag = match (true) {
            ! in_array($windowStatus, ['open', 'closed'], true), $notStarted === 0 => null,
            $notStarted === $scoring->count() => 'stalled',
            default => 'uneven',
        };

        return [
            'room' => $room,
            'candidates' => $candidateCount,
            'candidatesComplete' => $candidatesComplete,
            'expected' => $expected,
            'completed' => $completed,
            'completedSince' => (int) $scoring->sum('completedSince'),
            'percent' => $this->percent($completed, $expected),
            'flag' => $flag,
            'judgesNotStarted' => $notStarted,
            'judges' => $judges->map(function (array $judge): array {
                unset($judge['completedCandidateIds'], $judge['completedSince']);

                return $judge;
            })->all(),
        ];
    }

    /**
     * @param  Collection<int, JudgeSave>  $pairs
     * @param  array<string, list<PlaybackEvent>>  $playback
     * @param  array<int, array<int, int>>  $durations
     * @return array<string, mixed>
     */
    private function judgeRow(RoomJudge $judge, Collection $pairs, int $candidateCount, int $factorCount, ?int $versionPace, Carbon $now, ?Carbon $rateSince, array $playback, array $durations): array
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

        // A judge with only a few candidates done who scored recently is
        // working through their Room, not stuck — don't nudge them yet.
        $recentlyActive = $lastActivity instanceof Carbon && $lastActivity->gte($now->copy()->subHours(self::RECENT_HOURS));

        $heard = $pairs
            ->map(fn (JudgeSave $pair): ?float => ListeningEstimate::heardRatio(
                $playback[$judge->id.':'.$pair->candidateId] ?? [],
                $pair->firstAt,
                $durations[$pair->candidateId] ?? [],
            ))
            ->filter(fn (?float $ratio): bool => $ratio !== null)
            ->values();

        return [
            'judge' => $judge,
            'name' => $judge->user->name,
            'type' => $judgeType->label(),
            'scoring' => $scoring,
            'status' => $status,
            'idle' => $idle,
            'needsAttention' => $scoring && ($status === 'not_started' || ($status === 'just_started' && ! $recentlyActive) || $idle),
            'completed' => $completed,
            'partial' => $partial,
            'remaining' => $scoring ? $remaining : 0,
            'percent' => $this->percent($completed, $candidateCount),
            'sittings' => $sittings,
            'paceSeconds' => $ownPace,
            'paceIsEstimate' => $ownPace === null,
            'remainingMinutes' => $scoring && $pace !== null && $remaining > 0 ? (int) ceil($remaining * $pace / 60) : null,
            'listening' => [
                'measured' => $heard->count(),
                'heardPercent' => $heard->isEmpty() ? null : (int) floor((float) $heard->avg() * 100),
                'short' => $heard->filter(fn (float $ratio): bool => $ratio < self::SHORT_LISTEN_RATIO)->count(),
            ],
            'firstActivity' => $saves->first(),
            'lastActivity' => $lastActivity,
            'completedCandidateIds' => $completedPairs->pluck('candidateId')->all(),
            'completedSince' => $scoring && $rateSince !== null
                ? $completedPairs->filter(fn (JudgeSave $pair): bool => $pair->firstAt->gte($rateSince))->count()
                : 0,
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
