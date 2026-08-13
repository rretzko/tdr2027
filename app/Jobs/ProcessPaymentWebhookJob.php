<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Models\PaymentTransaction;
use App\Services\Payments\Dto\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Updates the payment_transactions row a vendor webhook refers to — see
 * epayment-integration.md §2.4. Dispatched after the webhook controller has
 * already verified the signature and responded 200, so a slow/failed run
 * here never causes the vendor to retry the whole delivery.
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly Vendor $vendor,
        private readonly WebhookEvent $event,
    ) {}

    public function handle(): void
    {
        $transaction = PaymentTransaction::where('vendor', $this->vendor)
            ->where('vendor_transaction_id', $this->event->vendorTransactionId)
            ->first();

        if ($transaction === null) {
            // Not idempotency-relevant (§2.4's idempotency guarantee is about
            // never duplicating a row on retry) — this is a webhook for a
            // transaction this app never created, which shouldn't normally
            // happen. Logged, not thrown: throwing would let the queue
            // retry an event that will never resolve to a match.
            Log::warning('ProcessPaymentWebhookJob: no matching payment_transactions row', [
                'vendor' => $this->vendor->value,
                'vendor_transaction_id' => $this->event->vendorTransactionId,
            ]);

            return;
        }

        // getRawOriginal(), not the magic-cast property — Larastan can't
        // infer PaymentTransaction::$status's PaymentTransactionStatus type
        // through the casts() accessor, same quirk as Vendor elsewhere in
        // this feature (see PaymentGatewayFactory's comment).
        $currentStatus = PaymentTransactionStatus::from($transaction->getRawOriginal('status'));

        // Vendors deliver webhooks at-least-once with no ordering guarantee
        // — don't let a stale/out-of-order event regress an already-final
        // status. The payload is still recorded either way, for audit.
        if ($this->statusRank($this->event->status) < $this->statusRank($currentStatus)) {
            $transaction->update(['raw_payload' => $this->event->rawPayload]);

            return;
        }

        $transaction->update([
            'status' => $this->event->status,
            'raw_payload' => $this->event->rawPayload,
            'paid_at' => $this->event->status === PaymentTransactionStatus::Completed
                ? ($transaction->paid_at ?? now())
                : $transaction->paid_at,
        ]);
    }

    private function statusRank(PaymentTransactionStatus $status): int
    {
        return match ($status) {
            PaymentTransactionStatus::Pending => 0,
            PaymentTransactionStatus::Failed, PaymentTransactionStatus::Completed => 1,
            PaymentTransactionStatus::Refunded => 2,
        };
    }
}
