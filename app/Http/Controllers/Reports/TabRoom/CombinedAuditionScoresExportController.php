<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports\TabRoom;

use App\Http\Controllers\Controller;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CombinedAuditionScoresExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, TabRoomReportService $reports, EnsembleCutoffService $cutoffs, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $ensembleId = (string) $request->query('ensemble_id');
        $confidential = $request->boolean('confidential');
        $filenameSuffix = $confidential ? 'confidential' : 'public';

        // DomPDF can't render this report inline at "All Ensembles" scale —
        // confirmed 2026-08-11, exceeded both a 120s execution limit and a
        // 2GB memory limit on real data. That PDF is generated in the
        // background and emailed instead (CombinedAuditionScores::
        // requestAllEnsemblesPdf()); a stale bookmarked/shared link to this
        // synchronous route must not be able to reproduce that crash. CSV
        // has no such cost and is unaffected.
        abort_if($format === 'pdf' && $ensembleId === 'all', 422, 'Use the "Email me the PDF" button on the report page — this export runs in the background.');

        $ensemble = $ensembleId === 'all' ? null : Ensemble::findOrFail($ensembleId);
        $voicePartTables = $ensemble !== null
            ? $reports->combinedScoreRows($version, $ensemble, $cutoffs)
            : $reports->allEnsemblesScoreRows($version, $cutoffs);
        $filenameEnsemblePart = $ensemble !== null ? (string) $ensemble->id : 'all';

        if ($format === 'csv') {
            // "All Ensembles" rows aren't grouped by Ensemble — a Voice Part
            // shared across several Ensembles is de-duplicated to one row
            // set, not repeated per Ensemble (see allEnsemblesScoreRows()'s
            // docblock), so there's no single Ensemble value to show per
            // row there. Result already carries the accepted Ensemble's
            // abbreviation (or na/inc/ns/err) — the only place an Ensemble
            // belongs in this export, per the product owner (2026-08-11).
            $headers = $ensemble !== null ? ['Ensemble', 'Voice Part', 'Candidate #'] : ['Voice Part', 'Candidate #'];

            if ($confidential) {
                $headers = [...$headers, 'Student', 'School', 'Teacher'];
            }

            $headers[] = 'Total';
            $headers[] = 'Result';

            $rows = $voicePartTables->flatMap(function (array $table) use ($ensemble, $confidential): array {
                return $table['rows']->map(function (array $row) use ($ensemble, $table, $confidential): array {
                    $line = $ensemble !== null
                        ? [$ensemble->name, $table['voicePart']->abbr, $row['candidate']->id]
                        : [$table['voicePart']->abbr, $row['candidate']->id];

                    if ($confidential) {
                        $line[] = $row['candidate']->student->user->sort_name;
                        $line[] = $row['candidate']->school->name ?? '';
                        $line[] = $row['candidate']->teacher->user->name;
                    }

                    $line[] = $row['total'];
                    $line[] = $row['result'];

                    return $line;
                })->all();
            });

            return CsvExport::stream("combined-audition-scores-{$filenameSuffix}-{$filenameEnsemblePart}.csv", $headers, $rows);
        }

        // 'all' + pdf already aborted above, so this must be a specific
        // Ensemble — but PHPStan can't trace that back through the earlier
        // abort_if(), hence the explicit guard.
        if ($ensemble === null) {
            abort(422);
        }

        return Pdf::loadView('pdf.reports.tab-room.combined-audition-scores', [
            'version' => $version,
            'ensembleTables' => collect([['sectionLabel' => $ensemble->name, 'voicePartTables' => $voicePartTables]]),
            'confidential' => $confidential,
        ])->setPaper('a4', 'landscape')->stream("combined-audition-scores-{$filenameSuffix}-{$filenameEnsemblePart}.pdf");
    }
}
