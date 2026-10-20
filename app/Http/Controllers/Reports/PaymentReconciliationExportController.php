<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\TeacherDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReconciliationExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $allSchoolRows = PaymentReconciliation::baseRows($version, $countyIds);
        $schoolRows = PaymentReconciliation::filterRows($allSchoolRows, (string) $request->query('search', ''));

        if ($format === 'csv') {
            // One line per school, matching the on-screen table — a school
            // with several teachers lists them "; "-joined, in the same
            // order, across the Teacher/Email/Mobile columns.
            $dollars = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

            return CsvExport::stream(
                "payment-reconciliation-{$version->id}.csv",
                ['School', 'Teacher', 'Email', 'Mobile', 'Registered', 'Due', 'Paid', 'Balance'],
                $schoolRows->map(fn (array $row): array => [
                    $row['school']->name ?? '',
                    $row['teachers']->map(fn (Teacher $t): string => $t->user->name)->implode('; '),
                    $row['teachers']->map(fn (Teacher $t): string => (string) $t->user->email)->implode('; '),
                    $row['teachers']->map(fn (Teacher $t): string => TeacherDisplay::cellPhoneFormatted($t) ?? '')->implode('; '),
                    $row['count'],
                    $dollars($row['dueCents']),
                    $dollars($row['paidCents']),
                    $dollars($row['balanceCents']),
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.payment-reconciliation', [
            'version' => $version,
            'schoolRows' => $schoolRows,
            'versionTotals' => PaymentReconciliation::versionTotals($allSchoolRows),
        ])->stream("payment-reconciliation-{$version->id}.pdf");
    }
}
