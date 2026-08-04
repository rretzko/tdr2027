<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema/report-only this phase — no writer exists yet, as there is no
 * candidate-facing e-payment integration built (see
 * event-version-orientation.md §5.10/§5.2). payment_type is conceptually
 * always Electronic; a Candidate has no other payment channel.
 */
#[Fillable([
    'version_id', 'candidate_id', 'payment_type',
    'amount', 'reference_number', 'comments', 'paid_at',
])]
class CandidatePayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function amountInDollars(): float
    {
        return $this->amount / 100;
    }
}
