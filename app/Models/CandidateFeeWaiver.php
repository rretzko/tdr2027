<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeType;
use Database\Factories\CandidateFeeWaiverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Event Manager's full waiver of one FeeType for one Candidate. The row's
 * existence is the waiver (always $0, never a partial amount); deleting it
 * restores the fee. Applied through CandidateFeeCalculator — see
 * docs/plans/student-fee-waivers.md.
 */
#[Fillable([
    'candidate_id', 'fee_type', 'reason', 'waived_by_user_id',
])]
class CandidateFeeWaiver extends Model
{
    /** @use HasFactory<CandidateFeeWaiverFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }
}
