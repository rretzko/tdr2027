<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'version_id', 'registration', 'on_site_registration',
    'participation', 'epayment_surcharge', 'housing',
])]
class VersionFee extends Model
{
    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function registrationInDollars(): float
    {
        return $this->registration / 100;
    }

    public function participationInDollars(): float
    {
        return $this->participation / 100;
    }

    /**
     * Registration and participation are never combined into one checkout
     * amount — see FeeType. The surcharge is additive once per transaction
     * regardless of fee type (an electronic-checkout add-on, never part of
     * what's "owed").
     */
    public function amountForCheckout(FeeType $feeType, int $candidateCount): int
    {
        $perCandidate = match ($feeType) {
            FeeType::Registration => $this->registration,
            FeeType::Participation => $this->participation,
        };

        return ($perCandidate * $candidateCount) + $this->epayment_surcharge;
    }
}
