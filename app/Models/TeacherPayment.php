<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual payment (check/purchase order/cash/other) recorded against a
 * specific school/teacher pairing via the Participating Schools report's
 * payment modal. Electronic is a reserved PaymentType case here — not
 * written by that modal (see event-version-orientation.md §5.10).
 */
#[Fillable([
    'version_id', 'school_id', 'teacher_id', 'payment_type',
    'amount', 'reference_number', 'comments', 'recorded_by_user_id',
])]
class TeacherPayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
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
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function amountInDollars(): float
    {
        return $this->amount / 100;
    }
}
