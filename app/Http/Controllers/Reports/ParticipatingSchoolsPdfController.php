<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ParticipatingSchoolsPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $rows = ParticipatingSchools::filterAndSort(
            ParticipatingSchools::baseRows($version, $countyIds),
            (string) $request->query('search', ''),
            (string) $request->query('packetFilter', ''),
            (string) $request->query('balanceFilter', ''),
            'school',
            'asc',
        );

        return Pdf::loadView('pdf.reports.participating-schools', [
            'version' => $version,
            'rows' => $rows,
        ])->stream("participating-schools-{$version->id}.pdf");
    }
}
