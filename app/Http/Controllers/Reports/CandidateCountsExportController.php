<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\CandidateCounts;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\TeacherDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateCountsExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $voiceParts = $version->availableVoiceParts();
        $candidates = CandidateCounts::scopedCandidates($version, $countyIds);
        $rows = CandidateCounts::filterAndSort(
            CandidateCounts::rows($candidates, $voiceParts),
            (string) $request->query('search', ''),
            'school',
            'asc',
        );

        if ($format === 'csv') {
            $headers = ['School', 'Teacher', 'Email', 'Phone', ...$voiceParts->pluck('abbr')->all(), 'Total'];

            return CsvExport::stream(
                "candidate-counts-{$version->id}.csv",
                $headers,
                $rows->map(fn (array $row): array => [
                    $row['school']->name ?? '',
                    $row['teacher']->user->name,
                    $row['teacher']->user->email,
                    TeacherDisplay::phoneNumbers($row['teacher']),
                    ...$voiceParts->map(fn ($vp) => $row['countsByVoicePart'][$vp->id])->all(),
                    $row['total'],
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.candidate-counts', [
            'version' => $version,
            'voiceParts' => $voiceParts,
            'summary' => CandidateCounts::voicePartSummary($candidates, $voiceParts),
            'rows' => $rows,
        ])->stream("candidate-counts-{$version->id}.pdf");
    }
}
