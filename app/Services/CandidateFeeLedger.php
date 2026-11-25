<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Version;
use Illuminate\Support\Collection;

/**
 * Due/paid/balance for one fee at a time. Registration, participation and
 * housing each have their own balance, so money paid toward one never hides
 * or inflates what's owed on another: with a shared balance, a student who
 * owed registration and paid participation was still offered the
 * participation fee. Due amounts come from CandidateFeeCalculator, so
 * waivers are always honored.
 */
final class CandidateFeeLedger
{
    public function __construct(private readonly CandidateFeeCalculator $calculator) {}

    /**
     * Cents owed for this fee, net of waivers. Registration has no status
     * gate here; callers decide whether the status owes or may pay it.
     */
    public function dueCents(Version $version, Candidate $candidate, FeeType $feeType): int
    {
        if ($feeType !== FeeType::Registration && ! $this->accrues($version, $candidate, $feeType)) {
            return 0;
        }

        return $this->calculator->feeCents($version, $candidate, $feeType);
    }

    public function paidCents(Candidate $candidate, FeeType $feeType): int
    {
        return $this->paidByCandidate([$candidate->id], $feeType)->get($candidate->id, 0);
    }

    public function balanceCents(Version $version, Candidate $candidate, FeeType $feeType): int
    {
        return $this->dueCents($version, $candidate, $feeType) - $this->paidCents($candidate, $feeType);
    }

    /**
     * Completed allocations per candidate for one fee, refunds netted out.
     *
     * @param  iterable<int, int>  $candidateIds
     * @return Collection<int, int> candidate_id => cents
     */
    public function paidByCandidate(iterable $candidateIds, FeeType $feeType): Collection
    {
        return PaymentAllocation::query()
            ->whereIn('candidate_id', collect($candidateIds)->all())
            ->whereHas('paymentTransaction', fn ($query) => $query
                ->where('status', PaymentTransactionStatus::Completed->value)
                ->where(PaymentTransaction::paysFee($feeType)))
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $allocations): int => (int) $allocations->sum('amount'));
    }

    /** Overpaid cents summed fee by fee, so one fee's balance can't mask another's overpayment. */
    public function overpaymentCents(Version $version, Candidate $candidate): int
    {
        return (int) collect(FeeType::cases())
            ->sum(fn (FeeType $type): int => max(0, -$this->balanceCents($version, $candidate, $type)));
    }

    private function accrues(Version $version, Candidate $candidate, FeeType $feeType): bool
    {
        $payable = $feeType === FeeType::Housing ? $version->housingFeePayable() : $version->participationFeePayable();

        // getRawOriginal(): Larastan can't infer the enum cast.
        return $payable && $candidate->getRawOriginal('status') === CandidateStatus::Accepted->value;
    }
}
