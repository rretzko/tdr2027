<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Enums\CandidateStatus;
use App\Enums\PdfExportStatus;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Services\CoTeacherAccessService;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A teacher's audition-outcome candidates for a single Version whose
 * results have been released (Tab Room Module.docx's Close Audition —
 * see TabRoom\CloseAudition), with a switcher (populated the same way as
 * ResultsIndex) so a teacher can jump between their released Versions
 * without going back through the dashboard.
 *
 * Was a placeholder until Ensemble Cut-offs existed (score/accepted/
 * ensemble columns hardcoded to "—", candidates filtered to status=
 * Registered — which is empty by the time a Version is actually closed,
 * since Ensemble Cut-offs moves everyone to accepted/not_accepted/
 * no_show/incomplete). Now real.
 */
#[Layout('components.layouts.app')]
class Results extends Component
{
    /**
     * The four actual audition outcomes — a Candidate reaches exactly one
     * of these via Ensemble Cut-offs (EnsembleCutoffService). Registered
     * (no decision yet) and the pre-adjudication states (eligible/pending/
     * withdrew/teacher_withdrawn) never appear in a results list.
     *
     * @var list<CandidateStatus>
     */
    private const RESULT_STATES = [
        CandidateStatus::Accepted,
        CandidateStatus::NotAccepted,
        CandidateStatus::NoShow,
        CandidateStatus::Incomplete,
    ];

    public Version $version;

    public string $switchVersionId = '';

    public string $sortColumn = 'name';

    public string $sortDirection = 'asc';

    #[Url]
    public string $schoolFilter = '';

    public function mount(Version $version, CoTeacherAccessService $coTeacherAccess): void
    {
        $teacher = $this->teacher();

        // ResultsIndex lists a Version here whenever the teacher has a
        // visible Candidate in it (any status, own or granted via a
        // co-teaching share) — not every such Version necessarily has a
        // surviving VersionInvitation row, so either standing admits.
        $hasStanding = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists()
            || $coTeacherAccess->candidateQuery($teacher)->where('version_id', $version->id)->exists();

        abort_unless($hasStanding, 403);
        abort_unless($version->results_released_at !== null, 403);

        $this->version = $version;
        $this->switchVersionId = (string) $version->id;
    }

    public function updatedSwitchVersionId(): void
    {
        if ($this->switchVersionId === (string) $this->version->id) {
            return;
        }

        $this->redirectRoute('registrations.results', ['version' => $this->switchVersionId], navigate: true);
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function render(TabRoomReportService $reports, EnsembleCutoffService $cutoffs, CoTeacherAccessService $coTeacherAccess): View
    {
        $candidates = $this->candidates($coTeacherAccess);

        return view('livewire.registrations.results', [
            'candidates' => $candidates,
            'schoolOptions' => $this->schoolOptions($coTeacherAccess),
            'switcherOptions' => $this->switcherOptions($coTeacherAccess),
            'schoolReports' => $this->schoolReports($candidates, $reports, $cutoffs),
            'candidateReports' => $this->candidateReports($candidates, $reports, $cutoffs),
            'shareResultsEnabled' => (bool) $this->version->share_results,
            'shareResultsReady' => $this->shareResultsReady(),
        ]);
    }

    /**
     * @return Collection<int, Candidate>
     */
    private function candidates(CoTeacherAccessService $coTeacherAccess): Collection
    {
        $teacher = $this->teacher();

        $candidates = $coTeacherAccess->candidateQuery($teacher)
            ->where('version_id', $this->version->id)
            ->whereIn('status', self::RESULT_STATES)
            ->when($this->schoolFilter !== '', fn (Builder $query): Builder => $query->where('school_id', $this->schoolFilter))
            ->with(['student.user', 'voicePart', 'acceptedEnsemble', 'auditionResult', 'school'])
            ->get();

        $sortValue = fn (Candidate $candidate): int|string => match ($this->sortColumn) {
            // voice_part_id is a non-nullable FK — voicePart is never null.
            'voice_part' => $candidate->voicePart->sort_order,
            'score' => $candidate->auditionResult !== null ? $candidate->auditionResult->total : -1,
            'result' => mb_strtolower($this->resultLabel($candidate)),
            'ensemble' => $candidate->acceptedEnsemble !== null ? mb_strtolower($candidate->acceptedEnsemble->name) : '',
            default => mb_strtolower($candidate->student->user->sort_name),
        };

        $sorted = $this->sortDirection === 'desc' ? $candidates->sortByDesc($sortValue) : $candidates->sortBy($sortValue);

        return $sorted->values();
    }

    /**
     * Every school among this teacher's *unfiltered* resolved-outcome
     * candidates — independent of $schoolFilter, so the dropdown's own
     * option list doesn't shrink to just the currently-selected school. The
     * view only renders the filter (and a School column) once this has more
     * than one entry; most often now via a co-teaching grant
     * (docs/plans/co-teacher-definition.md §3), but also a teacher genuinely
     * active at more than one school on their own.
     *
     * @return Collection<int, School>
     */
    private function schoolOptions(CoTeacherAccessService $coTeacherAccess): Collection
    {
        $teacher = $this->teacher();

        return $coTeacherAccess->candidateQuery($teacher)
            ->where('version_id', $this->version->id)
            ->whereIn('status', self::RESULT_STATES)
            ->with('school')
            ->get()
            ->pluck('school')
            ->filter()
            ->unique('id')
            ->sortBy(fn (School $school): string => $school->name)
            ->values();
    }

    /**
     * The Result column's displayed text — "Accepted" for an accepted
     * Candidate (not the generic status label, matching the badge the view
     * renders), or the status label otherwise. Shared between the sort
     * comparator above and nowhere else — the view computes its own badge
     * inline, this only needs to match it well enough to sort consistently.
     */
    private function resultLabel(Candidate $candidate): string
    {
        $status = CandidateStatus::from((string) $candidate->getRawOriginal('status'));

        return $status === CandidateStatus::Accepted
            ? 'Accepted'
            : $status->label();
    }

    /**
     * Per-School public score report data (Results page "view"/"download"
     * icons, one pair per distinct School the teacher has a resolved-outcome
     * Candidate at in this Version — usually one, but a teacher can teach at
     * more than one school). Each School's report is a set of single-
     * Candidate pages (TabRoomReportService::candidateScoreRow()), not one
     * shared multi-row table, matching the one-candidate-per-page PDF/modal
     * format requested for this report.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, array{school: School, pages: Collection<int, array{candidate: Candidate, table: array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}}>}>
     */
    private function schoolReports(Collection $candidates, TabRoomReportService $reports, EnsembleCutoffService $cutoffs): Collection
    {
        return $candidates
            ->groupBy('school_id')
            ->map(function (Collection $schoolCandidates) use ($reports, $cutoffs): array {
                $pages = $schoolCandidates
                    ->map(fn (Candidate $candidate): array => ['candidate' => $candidate, 'table' => $reports->candidateScoreRow($this->version, $candidate, $cutoffs)])
                    ->filter(fn (array $page): bool => $page['table'] !== null)
                    ->values();

                return ['school' => $schoolCandidates->first()->school, 'pages' => $pages];
            })
            ->filter(fn (array $report): bool => $report['school'] !== null && $report['pages']->isNotEmpty())
            ->values();
    }

    /**
     * Per-Person public score report data (Results page row-level "view"/
     * "download" icons), keyed by candidate_id for the row-loop's lookup.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, array{candidate: Candidate, table: array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}}>
     */
    private function candidateReports(Collection $candidates, TabRoomReportService $reports, EnsembleCutoffService $cutoffs): Collection
    {
        return $candidates
            ->map(fn (Candidate $candidate): array => ['candidate' => $candidate, 'table' => $reports->candidateScoreRow($this->version, $candidate, $cutoffs)])
            ->filter(fn (array $page): bool => $page['table'] !== null)
            ->keyBy(fn (array $page): int => $page['candidate']->id);
    }

    /**
     * Whether the Event Manager's optional "Share Results" all-Ensembles
     * public PDF (§ share_results) is both enabled and actually finished
     * generating — GenerateCombinedScoresPdfJob runs in the background
     * (queued at Close, see CloseAudition::close()), so there's a real
     * window right after results release where share_results is true but
     * no Completed export exists yet.
     */
    private function shareResultsReady(): bool
    {
        if (! $this->version->share_results) {
            return false;
        }

        return CombinedScoresPdfExport::where('version_id', $this->version->id)
            ->where('confidential', false)
            ->where('status', PdfExportStatus::Completed->value)
            ->exists();
    }

    /**
     * Other released Versions this teacher has standing in, for the
     * page-top switcher — same qualifying query as ResultsIndex::buildItems(),
     * so the switcher's options always match what's listed on the dashboard.
     *
     * @return Collection<int, Version>
     */
    private function switcherOptions(CoTeacherAccessService $coTeacherAccess): Collection
    {
        $teacher = $this->teacher();

        $versionIds = $coTeacherAccess->candidateQuery($teacher)->pluck('version_id')->unique();

        return Version::with('event')
            ->whereIn('id', $versionIds)
            ->whereNotNull('results_released_at')
            ->get()
            ->sort(function (Version $a, Version $b): int {
                $seniorClassOf = $b->senior_class_of <=> $a->senior_class_of;

                return $seniorClassOf !== 0 ? $seniorClassOf : $a->name <=> $b->name;
            })
            ->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
