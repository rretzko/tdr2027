<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentEnvironment;
use App\Enums\Vendor;
use Database\Factories\EventEpaymentConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-Event vendor credential — one Square/PayPal business per Event, not
 * per Version. Confirmed necessary 2026-08-13, mid-implementation: a real
 * screenshot showed two Events reporting to the same Organization (CJMEA,
 * NJMEA) are actually separate Square businesses, so the credential can't
 * live at the Version level (every new Version of the same Event would
 * otherwise need the same credential re-entered, with real drift/typo risk)
 * or app-wide (SquarePaymentGateway was first built that way and had to be
 * fixed).
 *
 * One row per (Event, PaymentEnvironment) — added 2026-08-13 so an Event can
 * hold a sandbox AND a production credential simultaneously without
 * overwriting one to test/verify the other. Which one is "active" app-wide
 * is a single deployment-level toggle (services.payments.environment), not
 * per-Event — see Event::activeEpaymentConfig().
 */
#[Fillable(['event_id', 'environment', 'vendor', 'vendor_account_id', 'secret', 'webhook_signature_key'])]
class EventEpaymentConfig extends Model
{
    /** @use HasFactory<EventEpaymentConfigFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => PaymentEnvironment::class,
            'vendor' => Vendor::class,
            'secret' => 'encrypted',
            'webhook_signature_key' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function epaymentAccepted(): bool
    {
        return $this->vendor !== null;
    }
}
