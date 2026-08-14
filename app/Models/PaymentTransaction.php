<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\Vendor;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "A payment happened" — separate from which candidate fee(s) it satisfies
 * (payment_allocations, §1.1 of epayment-integration.md). Replaces
 * candidate_payments/teacher_payments.
 */
#[Fillable([
    'version_id', 'source', 'vendor', 'vendor_transaction_id',
    'payer_teacher_id', 'payer_student_id', 'school_id', 'amount', 'status',
    'payment_type', 'fee_type', 'reference_number', 'comments', 'raw_payload',
    'recorded_by_user_id', 'paid_at',
])]
class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => PaymentSource::class,
            'vendor' => Vendor::class,
            'status' => PaymentTransactionStatus::class,
            'payment_type' => PaymentType::class,
            'fee_type' => FeeType::class,
            'raw_payload' => 'array',
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
     * @return BelongsTo<Teacher, $this>
     */
    public function payerTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'payer_teacher_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function payerStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'payer_student_id');
    }

    /**
     * Creation-time snapshot only — do not use for reconciliation/balance
     * math. See the migration's own comment and epayment-integration.md §1.1.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function amountInDollars(): float
    {
        return $this->amount / 100;
    }

    /**
     * Amount not yet tied to a candidate. Zero for candidate_epayment/manual
     * single-candidate rows, which are always auto-allocated 100% at
     * creation (§1.1). Uses the loaded allocations collection, not a fresh
     * allocations()->sum() query, so eager-loading ('allocations') avoids
     * an N+1 when called across a list of transactions.
     */
    public function unallocatedAmount(): int
    {
        return $this->amount - $this->allocations->sum('amount');
    }

    public function needsReconciliation(): bool
    {
        return $this->unallocatedAmount() > 0;
    }
}
