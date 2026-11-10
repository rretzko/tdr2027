<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FeeType;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates/removes a Candidate's CandidateFeeWaiver rows. Authorization
 * (VersionRoleAssignmentService::canWaiveFees()) is the caller's job.
 * See docs/plans/student-fee-waivers.md.
 */
class CandidateFeeWaiverService
{
    /**
     * Makes $waived the exact set of waived fee types among $manageable —
     * fee types outside $manageable (e.g. one the Version currently charges
     * $0 for, so the modal doesn't offer it) are left untouched, so a
     * waiver isn't silently lost if that fee is raised again later. The one
     * shared $reason is written to every waiver kept or added;
     * waived_by_user_id records who first waived each fee type.
     *
     * @param  list<FeeType>  $waived
     * @param  list<FeeType>  $manageable
     * @return array{added: list<FeeType>, removed: list<FeeType>}
     */
    public function sync(Candidate $candidate, array $waived, array $manageable, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($candidate, $waived, $manageable, $reason, $actor): array {
            $added = [];
            $removed = [];

            // At most three fee types, so one lookup each is fine.
            foreach ($manageable as $feeType) {
                $current = CandidateFeeWaiver::where('candidate_id', $candidate->id)
                    ->where('fee_type', $feeType->value)
                    ->first();
                $shouldBeWaived = in_array($feeType, $waived, true);

                if ($current === null) {
                    if ($shouldBeWaived) {
                        CandidateFeeWaiver::create([
                            'candidate_id' => $candidate->id,
                            'fee_type' => $feeType->value,
                            'reason' => $reason,
                            'waived_by_user_id' => $actor->id,
                        ]);
                        $added[] = $feeType;
                    }

                    continue;
                }

                if ($shouldBeWaived) {
                    $current->update(['reason' => $reason]);
                } else {
                    $current->delete();
                    $removed[] = $feeType;
                }
            }

            $candidate->unsetRelation('feeWaivers');

            return ['added' => $added, 'removed' => $removed];
        });
    }
}
