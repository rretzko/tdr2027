<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The Results page's "Per Person" public score report — one Candidate's own
 * score breakdown, same one-page/header format as an individual page of
 * SchoolScoreReportPdfController's report.
 */
class CandidateScoreReportPdfController extends Controller
{
    public function __invoke(Version $version, Candidate $candidate, TabRoomReportService $reports, EnsembleCutoffService $cutoffs): Response
    {
        abort_if($candidate->version_id !== $version->id, 404);

        $teacher = Auth::user()->teacher;
        abort_if($teacher === null || $candidate->teacher_id !== $teacher->id, 403);

        abort_if($version->results_released_at === null, 404);

        $table = $reports->candidateScoreRow($version, $candidate, $cutoffs);
        abort_if($table === null, 404);

        $candidate->loadMissing(['student.user', 'voicePart']);

        return Pdf::loadView('pdf.reports.registrations.candidate-score-report', [
            'version' => $version,
            'pages' => collect([['candidate' => $candidate, 'table' => $table]]),
        ])->setPaper('letter', 'landscape')->download("score-report-{$candidate->ref}.pdf");
    }
}
