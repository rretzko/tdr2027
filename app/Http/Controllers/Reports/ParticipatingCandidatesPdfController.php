<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\ParticipatingCandidates;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ParticipatingCandidatesPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $rows = ParticipatingCandidates::filterAndSort(
            ParticipatingCandidates::baseRows($version, $countyIds),
            (string) $request->query('search', ''),
            (string) $request->query('schoolFilter', ''),
            (string) $request->query('gradeFilter', ''),
            (string) $request->query('voicePartFilter', ''),
            'candidate',
            'asc',
        );

        return Pdf::loadView('pdf.reports.participating-candidates', [
            'version' => $version,
            'rows' => $rows,
        ])->stream("participating-candidates-{$version->id}.pdf");
    }
}
