<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use Illuminate\Support\Carbon;

/**
 * Stops a second electronic checkout while the first is still being
 * confirmed. Square sends the payer back to TDR as soon as they pay, but the
 * webhook that marks the payment_transactions row Completed arrives about two
 * minutes later — and until then nothing counted the payment, so the Pay
 * button was still there. Two v88 families paid twice that way, 5 and 18
 * seconds after their first payment.
 *
 * A recent Pending row is treated as "being confirmed" for PROCESSING_MINUTES.
 * Past that, a payer who abandoned checkout can start again; the row itself is
 * expired later by payments:expire-abandoned.
 */
final class CheckoutInProgressGuard
{
    public const PROCESSING_MINUTES = 30;

    /**
     * The newest Pending single-candidate checkout for this candidate and
     * fee, started within PROCESSING_MINUTES. Single-candidate checkouts are
     * allocated when they're created, so the allocation finds them.
     */
    public function forCandidate(Candidate $candidate, FeeType $feeType, ?Carbon $now = null): ?PaymentTransaction
    {
        return PaymentTransaction::where('status', PaymentTransactionStatus::Pending->value)
            ->where('fee_type', $feeType->value)
            ->where('created_at', '>=', $this->since($now))
            ->whereHas('allocations', fn ($q) => $q->where('candidate_id', $candidate->id))
            ->latest('created_at')
            ->first();
    }

    /**
     * The newest Pending group checkout this teacher started for this
     * Version and fee within PROCESSING_MINUTES. Group checkouts aren't
     * allocated to candidates until reconciled, so they're found by payer.
     */
    public function forTeacherGroup(Version $version, Teacher $teacher, FeeType $feeType, ?Carbon $now = null): ?PaymentTransaction
    {
        return PaymentTransaction::where('version_id', $version->id)
            ->where('source', PaymentSource::TeacherEpayment->value)
            ->where('payer_teacher_id', $teacher->id)
            ->where('status', PaymentTransactionStatus::Pending->value)
            ->where('fee_type', $feeType->value)
            ->where('created_at', '>=', $this->since($now))
            ->latest('created_at')
            ->first();
    }

    /**
     * When a payer may start a new checkout despite the given pending one.
     */
    public function retryAfter(PaymentTransaction $pending): Carbon
    {
        return Carbon::parse($pending->created_at)->addMinutes(self::PROCESSING_MINUTES);
    }

    private function since(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now())->copy()->subMinutes(self::PROCESSING_MINUTES);
    }
}
