<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\PaymentRoster;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PaymentRosterPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $rows = PaymentRoster::filterAndSort(
            PaymentRoster::baseRows($version, $countyIds),
            (string) $request->query('search', ''),
            (string) $request->query('schoolFilter', ''),
            (string) $request->query('paymentTypeFilter', ''),
            'school',
            'asc',
        );

        return Pdf::loadView('pdf.reports.payment-roster', [
            'version' => $version,
            'rows' => $rows,
        ])->stream("payment-roster-{$version->id}.pdf");
    }
}
