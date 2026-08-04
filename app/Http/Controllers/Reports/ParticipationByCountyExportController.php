<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\ParticipationByCounty;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParticipationByCountyExportController extends Controller
{
    public function __invoke(Version $version, string $format, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $rows = ParticipationByCounty::baseRows($version, $countyIds, $roles);

        if ($format === 'csv') {
            return CsvExport::stream(
                "participation-by-county-{$version->id}.csv",
                ['County', 'Obligated Teachers', 'Participating Teachers', 'Candidates', 'Manager'],
                $rows->map(fn (array $row): array => [
                    $row['county']->name,
                    $row['obligatedTeacherCount'],
                    $row['participatingTeacherCount'],
                    $row['candidateCount'],
                    $row['managerName'] ?? '',
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.participation-by-county', [
            'version' => $version,
            'rows' => $rows,
        ])->stream("participation-by-county-{$version->id}.pdf");
    }
}
