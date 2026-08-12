<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The Results page's "Per School" public score report — every one of the
 * requesting teacher's own resolved-outcome Candidates at one School, one
 * Candidate per page (TabRoomReportService::candidateScoreRow()), each
 * headed by that Candidate's name and Voice Part. Strictly scoped to
 * school_id AND teacher_id (not every co-teacher's Candidates at that
 * School) — resolved via clarifying question with the product owner.
 */
class SchoolScoreReportPdfController extends Controller
{
    /**
     * @var list<CandidateStatus>
     */
    private const RESULT_STATES = [
        CandidateStatus::Accepted,
        CandidateStatus::NotAccepted,
        CandidateStatus::NoShow,
        CandidateStatus::Incomplete,
    ];

    public function __invoke(Version $version, School $school, TabRoomReportService $reports, EnsembleCutoffService $cutoffs): Response
    {
        abort_if($version->results_released_at === null, 404);

        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $candidates = Candidate::where('version_id', $version->id)
            ->where('school_id', $school->id)
            ->where('teacher_id', $teacher->id)
            ->whereIn('status', self::RESULT_STATES)
            ->with(['student.user', 'voicePart'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name));

        // Nothing to show is also the right response for "not your school" —
        // a 404 either way, rather than a 403 that would confirm the School
        // exists and has candidates for someone else.
        abort_if($candidates->isEmpty(), 404);

        $pages = $candidates
            ->map(fn (Candidate $candidate): array => ['candidate' => $candidate, 'table' => $reports->candidateScoreRow($version, $candidate, $cutoffs)])
            ->filter(fn (array $page): bool => $page['table'] !== null)
            ->values();

        abort_if($pages->isEmpty(), 404);

        return Pdf::loadView('pdf.reports.registrations.candidate-score-report', [
            'version' => $version,
            'pages' => $pages,
        ])->setPaper('letter', 'landscape')->download(Str::slug("score-report-{$school->name}-{$version->name}").'.pdf');
    }
}
