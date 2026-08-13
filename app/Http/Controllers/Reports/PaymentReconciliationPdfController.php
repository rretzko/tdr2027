<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PaymentReconciliationPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $schoolRows = PaymentReconciliation::filterRows(
            PaymentReconciliation::baseRows($version, $countyIds),
            (string) $request->query('search', ''),
        );
        $versionTotals = PaymentReconciliation::versionTotals(PaymentReconciliation::baseRows($version, $countyIds));

        return Pdf::loadView('pdf.reports.payment-reconciliation', [
            'version' => $version,
            'schoolRows' => $schoolRows,
            'versionTotals' => $versionTotals,
        ])->stream("payment-reconciliation-{$version->id}.pdf");
    }
}
