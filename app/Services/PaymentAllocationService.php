<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\User;

/**
 * The shared allocate-to-candidates action epayment-integration.md §3
 * describes — used by both the teacher-scoped "Your Unreconciled Payments"
 * screen (§4 step 6, VersionDashboard) and, unscoped, by the Registration
 * Manager's Payment Reconciliation report (§4 step 8). Reconciling a group
 * payment can happen "in any number of passes" (§1.1) — each call here is
 * one such pass, and never touches allocations from a previous pass.
 */
class PaymentAllocationService
{
    /**
     * @param  array<int, int>  $amountsByCandidateId  candidate_id => amount in cents
     */
    public function allocateMany(PaymentTransaction $transaction, array $amountsByCandidateId, User $allocatedBy): void
    {
        $totalRequested = array_sum($amountsByCandidateId);

        abort_if(
            $totalRequested > $transaction->unallocatedAmount(),
            422,
            "That would allocate more than this payment's remaining balance.",
        );

        foreach ($amountsByCandidateId as $candidateId => $amount) {
            if ($amount <= 0) {
                continue;
            }

            PaymentAllocation::create([
                'payment_transaction_id' => $transaction->id,
                'candidate_id' => $candidateId,
                'amount' => $amount,
                'allocated_by_user_id' => $allocatedBy->id,
                'allocated_at' => now(),
            ]);
        }

        // unallocatedAmount() reads the loaded allocations collection — the
        // rows just created above aren't reflected there yet, so callers
        // that check remaining balance again after this method returns
        // need a fresh instance (e.g. $transaction->refresh()).
    }
}
