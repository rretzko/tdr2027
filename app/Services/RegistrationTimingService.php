<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\VersionDateType;
use App\Models\Version;
use App\Models\VersionDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When a Version's students were registered, by day, relative to its two
 * deadlines: the student (candidate) deadline and the teacher cut-off — the
 * Final Teacher Changes date, falling back to the Postmark Deadline when the
 * Version has none.
 * Production data shows most registrations land in the gap between the two
 * (teachers register after students finish), so the chart colors each day's
 * registrations by the phase they fell in.
 *
 * Counts each current registrant (CandidateStatus::registrantStates()) once,
 * on the day of its first move to Registered, so the bars add up to the
 * registrant headcount. Candidates without status history (legacy imports)
 * aren't counted; a Version with none returns null.
 */
final class RegistrationTimingService
{
    /** Phase keys, in stacking order. */
    public const PHASES = ['early', 'middle', 'late'];

    /**
     * @return ?array{
     *     rows: list<array{date: string, x: string, heading: string, early: int, middle: int, late: int}>,
     *     phases: list<array{field: string, label: string, count: int, percent: int}>,
     *     total: int,
     *     studentDeadline: ?Carbon,
     *     cutoff: ?Carbon,
     *     cutoffName: ?string,
     * }
     */
    public function forVersion(Version $version, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $registeredAt = DB::table('candidate_status_history as h')
            ->join('candidates as c', 'c.id', '=', 'h.candidate_id')
            ->where('c.version_id', $version->id)
            ->whereIn('c.status', array_map(fn (CandidateStatus $status): string => $status->value, CandidateStatus::registrantStates()))
            ->where('h.to_status', CandidateStatus::Registered->value)
            ->groupBy('h.candidate_id')
            ->selectRaw('MIN(h.created_at) as registered_at')
            ->pluck('registered_at')
            ->map(fn ($at): Carbon => Carbon::parse((string) $at));

        if ($registeredAt->isEmpty()) {
            return null;
        }

        $studentDeadline = $this->date($version, VersionDateType::Candidate, 'end_at');
        $cutoffName = 'the Final Teacher Changes date';
        $cutoff = $this->date($version, VersionDateType::FinalTeacherChanges, 'start_at');
        if ($cutoff === null) {
            $cutoffName = 'the Postmark Deadline';
            $cutoff = $this->date($version, VersionDateType::PostmarkDeadline, 'start_at');
        }
        $cutoffDay = $cutoff === null ? null : $this->day($cutoff);
        $studentDay = $studentDeadline === null ? null : $this->day($studentDeadline);

        $counts = [];
        foreach ($registeredAt as $at) {
            $counts[$this->day($at)->toDateString()][$this->phase($at, $studentDeadline, $cutoff)][] = 1;
        }

        $first = $this->day($registeredAt->min());
        $last = $this->day($registeredAt->max());
        // Once the cut-off has passed, run the axis through it so the quiet
        // days before it still show.
        if ($cutoffDay !== null && $cutoff->lte($now) && $cutoffDay->gt($last)) {
            $last = $cutoffDay;
        }

        $rows = [];
        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                ...$this->labels($day, $studentDay, $cutoffDay, $cutoffName),
                'early' => count($counts[$key]['early'] ?? []),
                'middle' => count($counts[$key]['middle'] ?? []),
                'late' => count($counts[$key]['late'] ?? []),
            ];
        }

        $total = $registeredAt->count();
        $phases = [];
        foreach (self::PHASES as $field) {
            $count = (int) array_sum(array_column($rows, $field));
            if ($count === 0) {
                continue;
            }
            $phases[] = [
                'field' => $field,
                'label' => $this->phaseLabel($field, $studentDeadline !== null, $cutoff === null ? null : $cutoffName),
                'count' => $count,
                'percent' => (int) round($count / $total * 100),
            ];
        }

        return [
            'rows' => $rows,
            'phases' => $phases,
            'total' => $total,
            'studentDeadline' => $studentDeadline?->forDisplay(),
            'cutoff' => $cutoff?->forDisplay(),
            'cutoffName' => $cutoff === null ? null : $cutoffName,
        ];
    }

    /**
     * early: by the student deadline; middle: after it, up to the cut-off;
     * late: after the cut-off. A missing deadline drops its boundary, so
     * everything before the next one is "middle".
     */
    private function phase(Carbon $at, ?Carbon $studentDeadline, ?Carbon $cutoff): string
    {
        if ($studentDeadline !== null && $at->lte($studentDeadline)) {
            return 'early';
        }

        return $cutoff !== null && $at->gt($cutoff) ? 'late' : 'middle';
    }

    private function phaseLabel(string $field, bool $hasStudentDeadline, ?string $cutoffName): string
    {
        return match ($field) {
            'early' => 'By the student deadline',
            'late' => 'After '.$cutoffName,
            default => match (true) {
                $hasStudentDeadline && $cutoffName !== null => 'Between the deadlines',
                $hasStudentDeadline => 'After the student deadline',
                $cutoffName !== null => 'By '.$cutoffName,
                default => 'Registered',
            },
        };
    }

    /**
     * Pre-formatted, since Flux draws non-date x values verbatim. With a
     * cut-off the x label counts days to it (U+2212 minus, not a
     * hyphen, which Flux's date sniffing would read as a date — same as
     * VersionScorecard::chartLabels()); otherwise the month and day.
     *
     * @return array{x: string, heading: string}
     */
    private function labels(Carbon $day, ?Carbon $studentDay, ?Carbon $cutoffDay, string $cutoffName): array
    {
        $heading = $day->format('l, M j');

        if ($studentDay !== null && $day->eq($studentDay)) {
            $heading .= ' · Student deadline';
        }

        if ($cutoffDay === null) {
            return ['x' => $day->format('M j'), 'heading' => $heading];
        }

        $offset = (int) $cutoffDay->diffInDays($day);
        $x = match (true) {
            $offset < 0 => "\u{2212}".abs($offset),
            $offset > 0 => '+'.$offset,
            default => '0',
        };

        if ($offset === 0) {
            $heading .= ' · '.ucfirst($cutoffName);
        } else {
            $heading .= ' · '.abs($offset).' '.(abs($offset) === 1 ? 'day' : 'days').($offset < 0 ? ' before ' : ' after ').$cutoffName;
        }

        return ['x' => $x, 'heading' => $heading];
    }

    /**
     * A display-timezone calendar day as a midnight Carbon, so whole-day
     * diffs between days are exact.
     */
    private function day(Carbon $at): Carbon
    {
        return Carbon::parse($at->copy()->forDisplay()->toDateString());
    }

    private function date(Version $version, VersionDateType $type, string $column): ?Carbon
    {
        $value = VersionDate::where('version_id', $version->id)
            ->where('date_type', $type)
            ->value($column);

        return $value === null ? null : Carbon::parse((string) $value);
    }
}
