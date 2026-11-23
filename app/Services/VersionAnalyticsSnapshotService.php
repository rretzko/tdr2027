<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionAnalyticsSnapshot;
use App\Models\VoicePart;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Freezes a Version's end-of-cycle analytics into one
 * version_analytics_snapshots row at Close Audition
 * (docs/plans/version-analytics-report.md). Three sections, one per phase
 * of a candidate's lifecycle — registration, adjudication, selection —
 * each built from the same services the live Scorecard uses, so the
 * snapshot and the Scorecard can't disagree about what a number means.
 *
 * Everything stored is plain JSON: models are reduced to ids and the few
 * labels a report needs, dates to ISO-8601 strings, enums to their values.
 * Students appear by candidate id only — the snapshot outlives the cycle,
 * so it keeps no student names.
 *
 * Registrant headcounts use CandidateStatus::registrantStates(), not
 * status = Registered: by Close nearly everyone has moved on to
 * Accepted/NotAccepted, so VersionScorecardService's live Registered
 * counts read zero here.
 */
final class VersionAnalyticsSnapshotService
{
    /** Bump when a section's shape changes. */
    // 2: selection.analysis (SelectionAnalysisService) added 2026-11-23.
    public const SCHEMA_VERSION = 2;

    public function __construct(
        private readonly VersionScorecardService $scorecard,
        private readonly CandidateFeeCalculator $fees,
        private readonly RegistrationTimingService $timing,
        private readonly SchoolRetentionService $retention,
        private readonly AdjudicationProgressService $adjudication,
        private readonly JudgeSurveySummaryService $survey,
        private readonly EnsembleCutoffService $cutoffs,
        private readonly SelectionAnalysisService $selectionAnalysis,
    ) {}

    public function capture(Version $version, ?User $capturedBy = null, ?Carbon $at = null): VersionAnalyticsSnapshot
    {
        $at ??= Carbon::now();

        return VersionAnalyticsSnapshot::updateOrCreate(
            ['version_id' => $version->id],
            [
                'schema_version' => self::SCHEMA_VERSION,
                'captured_at' => $at,
                'captured_by_user_id' => $capturedBy?->id,
                'registration' => $this->registration($version, $at),
                'adjudication' => $this->adjudication($version, $at),
                'selection' => $this->selection($version),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function registration(Version $version, Carbon $at): array
    {
        $metrics = $this->scorecard->metricsFor($version);
        $registrantStates = $this->statusValues(CandidateStatus::registrantStates());

        $registrants = Candidate::where('version_id', $version->id)
            ->whereIn('status', $registrantStates)
            ->get(['id', 'teacher_id', 'school_id']);

        $pending = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Pending->value)
            ->count();

        $feesDue = $this->fees->sumFeeCents($version, $registrants, FeeType::Registration);
        // Same settled-allocation sum as VersionScorecardService; it doesn't
        // depend on candidate status.
        $feesPaid = $metrics->registrationFeesPaidCents;

        $retention = $this->retention->forVersion($version, $at);
        if ($retention !== null) {
            $retention['prior'] = ['id' => $retention['prior']->id, 'name' => $retention['prior']->name];
        }

        return $this->plain([
            'counts' => [
                'invitedTeachers' => $metrics->invitedTeachers,
                'invitedSchools' => $metrics->invitedSchools,
                'eligibleStudents' => $metrics->eligibleStudents,
                'obligatedTeachers' => $metrics->obligatedTeachers,
                'obligatedSchools' => $metrics->obligatedSchools,
                'engagedStudents' => $pending + $registrants->count(),
                'registeredTeachers' => $registrants->pluck('teacher_id')->unique()->count(),
                'registeredSchools' => $registrants->pluck('school_id')->unique()->count(),
                'registeredStudents' => $registrants->count(),
            ],
            'fees' => [
                'dueCents' => $feesDue,
                'paidCents' => $feesPaid,
                'outstandingCents' => $feesDue - $feesPaid,
                'waivedCents' => $this->fees->configuredFeeCents($version, FeeType::Registration) * $registrants->count() - $feesDue,
            ],
            'timing' => $this->timing->forVersion($version, $at),
            'retention' => $retention,
            'uploads' => $this->uploads($version),
            'strandedPending' => $this->strandedPending($version),
        ]);
    }

    /**
     * Who uploaded each recording type: the candidate's student, the
     * candidate's teacher, or someone else (e.g. an Event Manager).
     *
     * @return list<array{type: string, student: int, teacher: int, other: int}>
     */
    private function uploads(Version $version): array
    {
        return DB::table('recordings as r')
            ->join('candidates as c', 'c.id', '=', 'r.candidate_id')
            ->leftJoin('students as s', 's.id', '=', 'c.student_id')
            ->leftJoin('teachers as t', 't.id', '=', 'c.teacher_id')
            ->where('r.version_id', $version->id)
            ->groupBy('r.file_type')
            ->orderBy('r.file_type')
            ->selectRaw('r.file_type as type')
            ->selectRaw('SUM(r.uploaded_by = s.user_id) as student')
            ->selectRaw('SUM(r.uploaded_by = t.user_id) as teacher')
            ->selectRaw('SUM(r.uploaded_by IS NULL OR (r.uploaded_by <> COALESCE(s.user_id, 0) AND r.uploaded_by <> COALESCE(t.user_id, 0))) as other')
            ->get()
            ->map(fn (object $row): array => [
                'type' => (string) $row->type,
                'student' => (int) $row->student,
                'teacher' => (int) $row->teacher,
                'other' => (int) $row->other,
            ])
            ->values()
            ->all();
    }

    /**
     * Candidates still Pending at Close — never registered, so never
     * adjudicated — grouped by teacher, most first.
     *
     * @return array{count: int, teachers: list<array{teacherId: int, name: string, count: int}>}
     */
    private function strandedPending(Version $version): array
    {
        $rows = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Pending->value)
            ->with('teacher.user')
            ->get()
            ->groupBy('teacher_id')
            ->map(fn ($group, $teacherId): array => [
                'teacherId' => (int) $teacherId,
                'name' => (string) ($group->first()?->teacher?->user->name ?? ''),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return ['count' => (int) $rows->sum('count'), 'teachers' => $rows->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjudication(Version $version, Carbon $at): array
    {
        $progress = $this->adjudication->forVersion($version, $at);

        $progress['rooms'] = array_map(fn (array $room): array => [
            ...$room,
            'room' => ['id' => $room['room']->id, 'name' => $room['room']->name],
            'judges' => array_map(function (array $judge): array {
                $judgeId = $judge['judge']->id;
                unset($judge['judge']);

                return ['id' => $judgeId, ...$judge];
            }, $room['judges']),
        ], $progress['rooms']);

        $progress['agreement']['gaps'] = array_map(fn (array $gap): array => [
            ...$gap,
            'candidate' => ['id' => $gap['candidate']->id, 'voicePartId' => $gap['candidate']->voice_part_id],
        ], $progress['agreement']['gaps']);

        return $this->plain([
            ...$progress,
            'survey' => $this->survey->forVersion($version),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function selection(Version $version): array
    {
        $voiceParts = VoicePart::whereIn('id', Candidate::where('version_id', $version->id)->select('voice_part_id'))
            ->orderBy('sort_order')
            ->get(['id', 'name', 'abbr', 'sort_order']);

        $ensembles = $version->ensembleOrder->map(fn ($order): Ensemble => $order->ensemble);

        $outcomes = Candidate::where('version_id', $version->id)
            ->whereIn('status', $this->statusValues(CandidateStatus::registrantStates()))
            ->select('voice_part_id', 'status', DB::raw('count(*) as cnt'))
            ->groupBy('voice_part_id', 'status')
            ->get()
            ->map(fn (Candidate $row): array => [
                'voicePartId' => (int) $row->voice_part_id,
                'status' => (string) $row->getRawOriginal('status'),
                'count' => (int) $row->getAttribute('cnt'),
            ])
            ->values()
            ->all();

        $accepted = $this->cutoffs->acceptedCounts($version)
            ->map(fn (array $row): array => ['ensembleId' => $row['ensemble']->id, 'voicePartId' => $row['voice_part_id'], 'count' => $row['count']])
            ->values()
            ->all();

        $cutoffs = DB::table('version_ensemble_cutoffs')
            ->where('version_id', $version->id)
            ->orderBy('voice_part_id')
            ->orderBy('ensemble_id')
            ->get(['ensemble_id', 'voice_part_id', 'score'])
            ->map(fn (object $row): array => ['ensembleId' => (int) $row->ensemble_id, 'voicePartId' => (int) $row->voice_part_id, 'score' => (int) $row->score])
            ->values()
            ->all();

        // Every decided candidate's total, for score distributions around
        // each cut-off. Candidate id only — no names.
        $results = DB::table('audition_results as ar')
            ->join('candidates as c', 'c.id', '=', 'ar.candidate_id')
            ->where('ar.version_id', $version->id)
            ->orderBy('ar.voice_part_order_by')
            ->orderBy('ar.total')
            ->get(['ar.candidate_id', 'ar.voice_part_id', 'ar.total', 'ar.score_count', 'c.status', 'c.accepted_ensemble_id'])
            ->map(fn (object $row): array => [
                'candidateId' => (int) $row->candidate_id,
                'voicePartId' => (int) $row->voice_part_id,
                'total' => (int) $row->total,
                'scoreCount' => (int) $row->score_count,
                'status' => (string) $row->status,
                'ensembleId' => $row->accepted_ensemble_id === null ? null : (int) $row->accepted_ensemble_id,
            ])
            ->values()
            ->all();

        return $this->plain([
            'strategy' => $version->getRawOriginal('cutoff_strategy'),
            'scoreOrder' => $version->getRawOriginal('score_order'),
            'voiceParts' => $voiceParts->map(fn (VoicePart $voicePart): array => ['id' => $voicePart->id, 'name' => $voicePart->name, 'abbr' => $voicePart->abbr])->values()->all(),
            'ensembles' => $ensembles->map(fn (Ensemble $ensemble): array => ['id' => $ensemble->id, 'name' => $ensemble->name, 'abbreviation' => $ensemble->abbreviation])->values()->all(),
            'outcomes' => $outcomes,
            'accepted' => $accepted,
            'cutoffs' => $cutoffs,
            'results' => $results,
            'analysis' => $this->selectionAnalysis->forVersion($version),
        ]);
    }

    /**
     * @param  list<CandidateStatus>  $statuses
     * @return list<string>
     */
    private function statusValues(array $statuses): array
    {
        return array_map(fn (CandidateStatus $status): string => $status->value, $statuses);
    }

    /**
     * Recursively reduces a section to JSON-safe values. Dates become
     * ISO-8601 strings and enums their values; any other object is a bug in
     * a section builder (an unmapped model would drag its whole attribute
     * set, student names included, into a row that outlives the cycle), so
     * it throws rather than guess.
     */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(fn (mixed $item): mixed => $this->plain($item), $value),
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof BackedEnum => $value->value,
            is_object($value) => throw new InvalidArgumentException('Unmapped '.$value::class.' in analytics snapshot.'),
            default => $value,
        };
    }
}
