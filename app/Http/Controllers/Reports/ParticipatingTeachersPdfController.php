<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\ParticipatingTeachers;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ParticipatingTeachersPdfController extends Controller
{
    public function __invoke(Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $rows = ParticipatingTeachers::filterAndSort(ParticipatingTeachers::baseRows($version, $countyIds), '', 'last_name', 'asc');

        return Pdf::loadView('pdf.reports.participating-teachers', [
            'version' => $version,
            'rows' => $rows,
        ])->stream("participating-teachers-{$version->id}.pdf");
    }
}
