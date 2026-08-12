<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports\TabRoom;

use App\Http\Controllers\Controller;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentSeniorityExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, TabRoomReportService $reports, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $ensemble = Ensemble::findOrFail($request->query('ensemble_id'));
        $rows = $reports->studentSeniorityRows($version, $ensemble);
        $years = $rows->isNotEmpty() ? array_keys($rows->first()['years']) : [];

        if ($format === 'csv') {
            $headers = ['Student', 'School', 'Teacher', 'Voice Part', 'Years Participated', ...array_map(strval(...), $years)];

            return CsvExport::stream(
                "student-seniority-{$ensemble->id}.csv",
                $headers,
                $rows->map(fn (array $row) => [
                    $row['candidate']->student->user->sort_name,
                    $row['candidate']->school->name ?? '',
                    $row['candidate']->teacher->user->name,
                    $row['candidate']->voicePart->abbr,
                    count(array_filter($row['years'])),
                    ...array_map(fn (bool $accepted): string => $accepted ? 'Y' : 'N', array_values($row['years'])),
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.tab-room.student-seniority', [
            'version' => $version,
            'ensemble' => $ensemble,
            'years' => $years,
            'rows' => $rows,
        ])->setPaper('letter', 'landscape')->stream("student-seniority-{$ensemble->id}.pdf");
    }
}
