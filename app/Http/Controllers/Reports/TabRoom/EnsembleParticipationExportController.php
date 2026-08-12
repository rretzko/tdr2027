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

class EnsembleParticipationExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, TabRoomReportService $reports, EnsembleCutoffService $cutoffs, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $ensemble = Ensemble::findOrFail($request->query('ensemble_id'));
        $rows = $reports->ensembleParticipationRows($version, $ensemble, $cutoffs);

        if ($format === 'csv') {
            $headers = ['Candidate #', 'Student', 'Grade', 'Voice Part', 'School', 'Teacher', 'Teacher Email', 'Emergency Contact', 'Emergency Phone', 'Total'];

            return CsvExport::stream(
                "ensemble-participation-{$ensemble->id}.csv",
                $headers,
                $rows->map(fn (array $row): array => [
                    $row['candidate']->id,
                    $row['candidate']->student->user->sort_name,
                    $row['candidate']->student->grade ?? '',
                    $row['candidate']->voicePart->abbr,
                    $row['candidate']->school->name ?? '',
                    $row['candidate']->teacher->user->name,
                    $row['candidate']->teacher->user->email,
                    $row['candidate']->emergencyContact->name ?? '',
                    $row['candidate']->emergencyContact->preferred_phone ?? '',
                    $row['total'],
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.tab-room.ensemble-participation', [
            'version' => $version,
            'ensemble' => $ensemble,
            'rows' => $rows,
        ])->setPaper('letter', 'landscape')->stream("ensemble-participation-{$ensemble->id}.pdf");
    }
}
