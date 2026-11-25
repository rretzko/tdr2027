<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\Vendor;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One fee, student by student, shared by the teacher's Participation Fees
 * page and the Event Manager's Payment Reconciliation so the two always
 * agree.
 *
 * @phpstan-type FeeRow array{candidate: Candidate, dueCents: int, waivedCents: int, paidCents: int, pendingCents: int, balanceCents: int, payments: Collection<int, PaymentAllocation>}
 */
final class FeeReconciliationService
{
    public const STUDENT_HEADERS = ['Student', 'School', 'Teacher', 'Ensemble', 'Voice Part', 'Due', 'Waived', 'Paid', 'Pending', 'Balance', 'Payments'];

    public function __construct(
        private readonly CandidateFeeLedger $ledger,
        private readonly CandidateFeeCalculator $calculator,
        private readonly CoTeacherAccessService $coTeacherAccess,
    ) {}

    /**
     * @return Collection<int, FeeRow>
     */
    public function rowsForTeacher(Version $version, Teacher $teacher, FeeType $feeType): Collection
    {
        return $this->rows($version, $this->coTeacherAccess->candidateQuery($teacher), $feeType);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, FeeRow>
     */
    public function rowsForVersion(Version $version, ?array $countyIds, FeeType $feeType): Collection
    {
        return $this->rows(
            $version,
            Candidate::query()->when($countyIds !== null, fn (Builder $query) => $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds))),
            $feeType,
        );
    }

    /**
     * Every candidate who owes the fee, plus any who paid it and no longer
     * owes (shown as overpaid, never dropped).
     *
     * @param  Builder<Candidate>  $query
     * @return Collection<int, FeeRow>
     */
    private function rows(Version $version, Builder $query, FeeType $feeType): Collection
    {
        $owingStates = array_map(fn ($status): string => $status->value, $version->feeOwedStates($feeType));

        $candidates = $query
            ->where('version_id', $version->id)
            ->where(fn (Builder $query) => $query
                ->whereIn('status', $owingStates)
                ->orWhereHas('paymentAllocations.paymentTransaction', fn ($q) => $q
                    ->where('status', PaymentTransactionStatus::Completed->value)
                    ->where(PaymentTransaction::paysFee($feeType))))
            ->with(['student.user', 'school', 'teacher.user', 'acceptedEnsemble', 'voicePart', 'feeWaivers'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name))
            ->values();

        $allocations = PaymentAllocation::whereIn('candidate_id', $candidates->pluck('id'))
            ->whereHas('paymentTransaction', fn ($q) => $q
                ->whereIn('status', [PaymentTransactionStatus::Completed->value, PaymentTransactionStatus::Pending->value])
                ->where(PaymentTransaction::paysFee($feeType)))
            ->with('paymentTransaction')
            ->get();

        $rows = [];

        foreach ($candidates as $candidate) {
            $owes = in_array($candidate->getRawOriginal('status'), $owingStates, true);
            $due = $owes ? $this->ledger->dueCents($version, $candidate, $feeType) : 0;
            $payments = [];
            $paid = 0;
            $pending = 0;

            foreach ($allocations->where('candidate_id', $candidate->id) as $allocation) {
                if ($allocation->paymentTransaction?->getRawOriginal('status') === PaymentTransactionStatus::Completed->value) {
                    $payments[] = $allocation;
                    $paid += (int) $allocation->amount;
                } else {
                    $pending += (int) $allocation->amount;
                }
            }

            $rows[] = [
                'candidate' => $candidate,
                'dueCents' => $due,
                'waivedCents' => $owes && $candidate->hasFeeWaived($feeType) ? $this->calculator->configuredFeeCents($version, $feeType) : 0,
                'paidCents' => $paid,
                'pendingCents' => $pending,
                'balanceCents' => $due - $paid,
                'payments' => new Collection($payments),
            ];
        }

        return new Collection($rows);
    }

    /**
     * @param  Collection<int, FeeRow>  $rows
     * @return array{count: int, dueCents: int, waivedCents: int, paidCents: int, pendingCents: int, balanceCents: int}
     */
    public static function totals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'dueCents' => (int) $rows->sum('dueCents'),
            'waivedCents' => (int) $rows->sum('waivedCents'),
            'paidCents' => (int) $rows->sum('paidCents'),
            'pendingCents' => (int) $rows->sum('pendingCents'),
            'balanceCents' => (int) $rows->sum('balanceCents'),
        ];
    }

    /**
     * Totals per accepted Ensemble, for the Participation fee.
     *
     * @param  Collection<int, FeeRow>  $rows
     * @return Collection<string, array{count: int, dueCents: int, waivedCents: int, paidCents: int, pendingCents: int, balanceCents: int}>
     */
    public static function ensembleTotals(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row['candidate']->acceptedEnsemble->name ?? 'No ensemble'))
            ->sortKeys()
            ->map(fn (Collection $group): array => self::totals($group));
    }

    /**
     * Completed e-payments with partial refunds the vendor reported and
     * nobody has recorded against a student yet. A manual refund carrying
     * the payment's vendor id settles them.
     *
     * @param  list<int>|null  $countyIds
     * @return Collection<int, array{transaction: PaymentTransaction, unrecordedCents: int}>
     */
    public function unrecordedRefunds(Version $version, ?array $countyIds): Collection
    {
        $transactions = PaymentTransaction::where('version_id', $version->id)
            ->where('status', PaymentTransactionStatus::Completed->value)
            ->whereNotNull('raw_payload->tdr_partial_refunds')
            ->with(['allocations.candidate.student.user', 'school'])
            ->get();

        $entries = [];

        foreach ($transactions as $transaction) {
            if ($countyIds !== null && ! in_array($transaction->school?->county_id, $countyIds, true)) {
                continue;
            }

            $recorded = -(int) PaymentTransaction::where('version_id', $version->id)
                ->where('payment_type', PaymentType::Refund->value)
                ->where('reference_number', $transaction->vendor_transaction_id)
                ->sum('amount');
            $unrecorded = (int) array_sum($transaction->reportedPartialRefunds()) - $recorded;

            if ($unrecorded > 0) {
                $entries[] = ['transaction' => $transaction, 'unrecordedCents' => $unrecorded];
            }
        }

        return new Collection($entries);
    }

    /**
     * Every Completed payment of one fee (all fees when null), for matching
     * against the vendor's own statement.
     *
     * @param  list<int>|null  $countyIds
     * @return Collection<int, PaymentTransaction>
     */
    public function payments(Version $version, ?array $countyIds, ?FeeType $feeType): Collection
    {
        return PaymentTransaction::where('version_id', $version->id)
            ->where('status', PaymentTransactionStatus::Completed->value)
            ->when($feeType !== null, fn ($query) => $query->where(PaymentTransaction::paysFee($feeType)))
            ->with(['allocations.candidate.student.user', 'school', 'payerTeacher.user', 'payerStudent.user'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->toBase()
            ->filter(fn (PaymentTransaction $t): bool => $countyIds === null || in_array($t->school?->county_id, $countyIds, true))
            ->values();
    }

    /**
     * @param  Collection<int, FeeRow>  $rows
     * @return list<list<string>>
     */
    public static function studentCsvRows(Collection $rows): array
    {
        $dollars = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

        return $rows->map(fn (array $row): array => [
            $row['candidate']->student->user->sort_name,
            $row['candidate']->school->name ?? '',
            $row['candidate']->teacher->user->name,
            $row['candidate']->acceptedEnsemble->name ?? '',
            $row['candidate']->voicePart->abbr,
            $dollars($row['dueCents']),
            $dollars($row['waivedCents']),
            $dollars($row['paidCents']),
            $dollars($row['pendingCents']),
            $dollars($row['balanceCents']),
            self::paymentSummary($row['payments']),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $payments
     */
    public static function paymentSummary(Collection $payments): string
    {
        return $payments->map(function (PaymentAllocation $allocation): string {
            $transaction = $allocation->paymentTransaction;

            if ($transaction === null) {
                return '';
            }

            $date = Carbon::parse((string) ($transaction->getRawOriginal('paid_at') ?? $transaction->getRawOriginal('created_at')))->format('M j, Y');
            $reference = $transaction->reference_number ?? $transaction->vendor_transaction_id;

            return self::methodLabel($transaction).' '.number_format($allocation->amount / 100, 2, '.', '').' '.$date.($reference ? " ({$reference})" : '');
        })->filter()->implode('; ');
    }

    public static function methodLabel(PaymentTransaction $transaction): string
    {
        $type = $transaction->getRawOriginal('payment_type');
        $vendor = $transaction->getRawOriginal('vendor');

        return match (true) {
            $type !== null => PaymentType::from($type)->label(),
            $vendor !== null => Vendor::from($vendor)->label(),
            default => PaymentSource::from((string) $transaction->getRawOriginal('source'))->label(),
        };
    }

    /** The vendor's own id for the money movement (PayPal's transaction id). */
    public static function vendorCaptureId(PaymentTransaction $transaction): ?string
    {
        $payload = json_decode((string) $transaction->getRawOriginal('raw_payload'), true);
        $id = data_get($payload, 'resource.id') ?? data_get($payload, 'data.object.payment.id') ?? data_get($payload, 'payment_id');

        return is_string($id) ? $id : null;
    }
}
