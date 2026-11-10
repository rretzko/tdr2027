<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Models\Candidate;
use App\Models\Version;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single place a Candidate's owed fee amounts are computed, with
 * per-student fee waivers (CandidateFeeWaiver) applied. Every report,
 * roster, form and email that shows "due" goes through here so a waiver can
 * never be honored in one view and missed in another — see
 * docs/plans/student-fee-waivers.md.
 *
 * Amounts are cents. A Version with no version_fees row yet (brand-new,
 * non-cloned) owes 0 for every FeeType.
 */
class CandidateFeeCalculator
{
    /**
     * The configured per-candidate amount for $feeType, or 0 when that fee
     * is waived for this Candidate. No timing or status gating — callers
     * decide whether the fee applies at all (see combinedDueCents()).
     */
    public function feeCents(Version $version, Candidate $candidate, FeeType $feeType): int
    {
        if ($candidate->hasFeeWaived($feeType)) {
            return 0;
        }

        return $this->configuredFeeCents($version, $feeType);
    }

    /**
     * Registration (always) plus participation (only once the Version is
     * closed, and only for an Accepted Candidate), each net of waivers.
     * Housing deliberately stays outside this combined figure — it has its
     * own independent due/paid pair (see CandidateDetail::render()). Callers
     * decide whether the Candidate's status owes anything at all
     * (CandidateStatus::registrationFeeOwedStates()).
     */
    public function combinedDueCents(Version $version, Candidate $candidate): int
    {
        $due = $this->feeCents($version, $candidate, FeeType::Registration);

        // getRawOriginal(), not the magic-cast property — cataloged Larastan
        // enum-cast quirk (see PHPStan-quirks memory).
        if ($version->participationFeePayable() && $candidate->getRawOriginal('status') === CandidateStatus::Accepted->value) {
            $due += $this->feeCents($version, $candidate, FeeType::Participation);
        }

        return $due;
    }

    /**
     * Sum of feeCents() for $feeType across $candidates — waiver-aware
     * replacement for "count × configured fee".
     *
     * @param  Collection<int, Candidate>  $candidates
     */
    public function sumFeeCents(Version $version, Collection $candidates, FeeType $feeType): int
    {
        $this->loadWaivers($candidates);

        return (int) $candidates->sum(fn (Candidate $candidate): int => $this->feeCents($version, $candidate, $feeType));
    }

    /**
     * Eager-loads feeWaivers once for a whole collection so per-candidate
     * calls don't N+1. Safe to call repeatedly.
     *
     * @param  Collection<int, Candidate>  $candidates
     */
    public function loadWaivers(Collection $candidates): void
    {
        $candidates->loadMissing('feeWaivers');
    }

    public function configuredFeeCents(Version $version, FeeType $feeType): int
    {
        $fees = $version->fees;

        if ($fees === null) {
            return 0;
        }

        return (int) match ($feeType) {
            FeeType::Registration => $fees->registration,
            FeeType::Participation => $fees->participation,
            FeeType::Housing => $fees->housing,
        };
    }
}
