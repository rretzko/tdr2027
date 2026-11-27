<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlatformInvoiceStatus;
use Database\Factories\VersionPlatformInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TDR's platform-usage invoice for one Version (MFR Holdings, LLC → the
 * Event's organization). registrant_count / rate_cents / amount_cents /
 * recipients / number / issued_at / due_at are a snapshot taken at send
 * time and never recomputed afterwards — Resend re-mails the snapshot.
 * Lifecycle and rationale: App\Services\PlatformInvoiceService and
 * docs/plans/close-audition-invoice.md.
 *
 * @property Carbon|null $send_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $last_resent_at
 * @property int|null $registrant_count
 * @property int|null $rate_cents
 * @property int|null $amount_cents
 * @property int $resend_count
 * @property Carbon|null $storage_access_at
 * @property Carbon|null $storage_close_at
 * @property int|null $storage_months
 * @property int|null $storage_rate_cents
 * @property bool $storage_included
 * @property string|null $attention_reason
 * @property list<string>|null $recipients
 * @property bool $needs_attention
 */
#[Fillable([
    'version_id', 'number', 'status', 'closed_by_user_id',
    'send_at', 'sent_at', 'issued_at', 'due_at',
    'registrant_count', 'rate_cents', 'amount_cents', 'recipients', 'needs_attention', 'attention_reason',
    'storage_access_at', 'storage_close_at', 'storage_months', 'storage_rate_cents', 'storage_included',
    'paid_at', 'paid_by_user_id', 'payment_note',
    'last_resent_at', 'resend_count', 'voided_reason',
])]
class VersionPlatformInvoice extends Model
{
    /** @use HasFactory<VersionPlatformInvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlatformInvoiceStatus::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_resent_at' => 'datetime',
            'registrant_count' => 'integer',
            'rate_cents' => 'integer',
            'amount_cents' => 'integer',
            'resend_count' => 'integer',
            'storage_access_at' => 'datetime',
            'storage_close_at' => 'datetime',
            'storage_months' => 'integer',
            'storage_rate_cents' => 'integer',
            'storage_included' => 'boolean',
            'recipients' => 'array',
            'needs_attention' => 'boolean',
        ];
    }

    /**
     * Raw-value status read — Larastan can't see enum casts declared via
     * casts() (PHPStan-quirks memory), so callers compare against this.
     */
    public function statusEnum(): PlatformInvoiceStatus
    {
        return PlatformInvoiceStatus::from((string) ($this->getAttributes()['status'] ?? ''));
    }

    public function isOverdue(): bool
    {
        return $this->statusEnum() === PlatformInvoiceStatus::Sent
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
