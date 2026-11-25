<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Models\Candidate;
use App\Models\Teacher;
use App\Services\CandidateFeeLedger;
use App\Services\CoTeacherAccessService;
use App\Services\Payments\CheckoutInProgressGuard;
use App\Services\Payments\PaymentGatewayFactory;
use Flux\Flux;
use Illuminate\Support\Collection;

/**
 * Teacher checkout for one or more selected candidates, shared by the
 * Version dashboard and the Participation Fees page (both have a $version).
 * One payment_transactions row covers the selection; the gateway allocates
 * it to each selected candidate.
 */
trait StartsGroupCheckout
{
    abstract private function teacher(): Teacher;

    /**
     * @param  array<int, int|string>  $candidateIds
     */
    private function startGroupCheckout(array $candidateIds, FeeType $feeType): void
    {
        abort_unless($this->version->epaymentTeacherReady(), 403);
        abort_unless(match ($feeType) {
            FeeType::Registration => $this->version->registrationFeePayableByTeacher(),
            FeeType::Participation => $this->version->participationFeePayableOnline(),
            FeeType::Housing => $this->version->housingFeePayable(),
        }, 403);
        abort_if($candidateIds === [], 422, 'Select at least one candidate to pay for.');

        $eligibleStates = $this->version->feePayableStates($feeType);

        // Defensive filter: the UI only offers eligible candidates. Paying
        // depends on the acting teacher, whether the candidate is their own
        // or a granted co-teacher's (docs/plans/co-teacher-definition.md).
        $candidates = app(CoTeacherAccessService::class)->candidateQuery($this->teacher())
            ->whereIn('id', $candidateIds)
            ->where('version_id', $this->version->id)
            ->with('feeWaivers')
            ->get()
            ->filter(fn (Candidate $candidate): bool => in_array(
                CandidateStatus::from((string) $candidate->getRawOriginal('status')),
                $eligibleStates,
                true,
            ))
            ->values();

        abort_if($candidates->isEmpty(), 422);

        // A waived fee is never charged (docs/plans/student-fee-waivers.md).
        // Dropping waived candidates also keeps amountForCheckout()'s
        // count × fee correct.
        $candidates = $candidates
            ->reject(fn (Candidate $candidate): bool => $candidate->hasFeeWaived($feeType))
            ->values();

        if ($candidates->isEmpty()) {
            Flux::toast("The {$feeType->label()} is waived for every selected student, so there's nothing to pay.", variant: 'warning');

            return;
        }

        // Stop rather than charge again. Not dropped silently: the redirect
        // to the vendor follows, so a "skipped" notice would never be seen.
        $alreadyPaid = $this->alreadyPaid($candidates, $feeType);
        if ($alreadyPaid->isNotEmpty()) {
            Flux::toast(
                "Already paid: {$alreadyPaid->pluck('program_name')->implode(', ')}. Deselect them to pay for the rest.",
                variant: 'warning',
            );

            return;
        }

        $guard = app(CheckoutInProgressGuard::class);
        $pending = $guard->forTeacherGroup($this->version, $this->teacher(), $feeType);
        if ($pending !== null) {
            Flux::toast(
                "Your last group payment is still being confirmed. If the payment page was closed without paying, you can try again after {$guard->retryAfter($pending)->forDisplay()->format('g:i A')}.",
                variant: 'warning',
            );

            return;
        }

        // Someone else (the student, a co-teacher) may be paying right now.
        $inProgress = $candidates->filter(fn (Candidate $candidate): bool => $guard->forCandidate($candidate, $feeType) !== null);
        if ($inProgress->isNotEmpty()) {
            Flux::toast(
                "A payment is already being confirmed for: {$inProgress->pluck('program_name')->implode(', ')}. Deselect them to pay for the rest.",
                variant: 'warning',
            );

            return;
        }

        $session = app(PaymentGatewayFactory::class)->make($this->version)
            ->createCheckoutSession($this->version, $candidates, $this->teacher(), $feeType);

        $this->redirect($session->redirectUrl, navigate: false);
    }

    /**
     * Selected candidates with nothing left to pay for this fee.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, Candidate>
     */
    private function alreadyPaid(Collection $candidates, FeeType $feeType): Collection
    {
        $ledger = app(CandidateFeeLedger::class);
        $paid = $ledger->paidByCandidate($candidates->pluck('id'), $feeType);

        return $candidates->filter(function (Candidate $candidate) use ($ledger, $paid, $feeType): bool {
            $paidCents = $paid->get($candidate->id, 0);

            // A candidate with no fee configured at all is left to the
            // gateway's own check.
            return $paidCents > 0 && $ledger->dueCents($this->version, $candidate, $feeType) <= $paidCents;
        })->values();
    }
}
