<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Enums\FeeType;
use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\FeeReconciliationService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\TeacherDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReconciliationExportController extends Controller
{
    public function __invoke(Request $request, Version $version, string $format, VersionRoleAssignmentService $roles, FeeReconciliationService $reconciliation): Response|StreamedResponse
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $feeType = FeeType::tryFrom((string) $request->query('fee', ''));
        $detail = (string) $request->query('detail', '');
        $search = (string) $request->query('search', '');
        $suffix = $feeType !== null ? "-{$feeType->value}" : '';

        if ($detail === 'students' && $feeType !== null) {
            $rows = PaymentReconciliation::filterStudentRows($reconciliation->rowsForVersion($version, $countyIds, $feeType), $search);

            // Payment-by-payment detail is Event Manager only, as on screen.
            if (! $roles->canManageEvent(Auth::user(), $version->event)) {
                $rows = $rows->map(fn (array $row): array => [...$row, 'payments' => collect()]);
            }

            if ($format === 'csv') {
                return CsvExport::stream("fees-by-student-{$version->id}{$suffix}.csv", FeeReconciliationService::STUDENT_HEADERS, FeeReconciliationService::studentCsvRows($rows));
            }

            return Pdf::loadView('pdf.fee-reconciliation', [
                'title' => $feeType->label().' by Student',
                'version' => $version,
                'subheading' => 'Payment Reconciliation',
                'rows' => $rows,
                'totals' => FeeReconciliationService::totals($rows),
            ])->stream("fees-by-student-{$version->id}{$suffix}.pdf");
        }

        if ($detail === 'payments' && $format === 'csv') {
            abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

            return $this->paymentsCsv($reconciliation->payments($version, $countyIds, $feeType), "payments-{$version->id}{$suffix}.csv");
        }

        $allSchoolRows = PaymentReconciliation::baseRows($version, $countyIds, $feeType);
        $schoolRows = PaymentReconciliation::filterRows($allSchoolRows, (string) $request->query('search', ''));

        if ($format === 'csv') {
            // One line per school, matching the on-screen table — a school
            // with several teachers lists them "; "-joined, in the same
            // order, across the Teacher/Email/Mobile columns.
            $dollars = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

            return CsvExport::stream(
                "payment-reconciliation-{$version->id}{$suffix}.csv",
                // Waived is last (not beside Due) so the existing columns keep
                // their positions for anyone's saved spreadsheets.
                ['School', 'Teacher', 'Email', 'Mobile', 'Registered', 'Due', 'Paid', 'Balance', 'Waived'],
                $schoolRows->map(fn (array $row): array => [
                    $row['school']->name ?? '',
                    $row['teachers']->map(fn (Teacher $t): string => $t->user->name)->implode('; '),
                    $row['teachers']->map(fn (Teacher $t): string => (string) $t->user->email)->implode('; '),
                    $row['teachers']->map(fn (Teacher $t): string => TeacherDisplay::cellPhoneFormatted($t) ?? '')->implode('; '),
                    $row['count'],
                    $dollars($row['dueCents']),
                    $dollars($row['paidCents']),
                    $dollars($row['balanceCents']),
                    $dollars($row['waivedCents']),
                ]),
            );
        }

        return Pdf::loadView('pdf.reports.payment-reconciliation', [
            'version' => $version,
            'feeType' => $feeType,
            'schoolRows' => $schoolRows,
            'versionTotals' => PaymentReconciliation::versionTotals($allSchoolRows),
        ])->stream("payment-reconciliation-{$version->id}{$suffix}.pdf");
    }

    /**
     * One line per payment, with the ids that match it to the vendor's own
     * statement.
     *
     * @param  Collection<int, PaymentTransaction>  $payments
     */
    private function paymentsCsv(Collection $payments, string $filename): StreamedResponse
    {
        $dollars = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

        return CsvExport::stream(
            $filename,
            ['Paid', 'Fee', 'Method', 'Payer', 'School', 'Amount', 'Allocated', 'Unallocated', 'Vendor Order ID', 'Vendor Transaction ID', 'Reference', 'Students'],
            $payments->map(function (PaymentTransaction $payment) use ($dollars): array {
                $unallocated = $payment->unallocatedAmount();

                return [
                    Carbon::parse((string) ($payment->getRawOriginal('paid_at') ?? $payment->getRawOriginal('created_at')))->format('Y-m-d H:i'),
                    FeeType::from((string) ($payment->getRawOriginal('fee_type') ?? FeeType::Registration->value))->label(),
                    FeeReconciliationService::methodLabel($payment),
                    $payment->payerStudent->user->name ?? $payment->payerTeacher->user->name ?? '',
                    $payment->school->name ?? '',
                    $dollars($payment->amount),
                    $dollars($payment->amount - $unallocated),
                    $dollars($unallocated),
                    $payment->vendor_transaction_id ?? '',
                    FeeReconciliationService::vendorCaptureId($payment) ?? '',
                    $payment->reference_number ?? '',
                    $payment->allocations->map(fn (PaymentAllocation $a): string => ($a->candidate->student->user->sort_name ?? '').' '.$dollars($a->amount))->implode('; '),
                ];
            })->values(),
        );
    }
}
