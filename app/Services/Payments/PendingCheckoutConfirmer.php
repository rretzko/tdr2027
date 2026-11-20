<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\PaymentTransaction;

/**
 * Settles a pending checkout from the vendor's own answer when the payer
 * lands back on TDR, instead of waiting for the webhook (about two minutes
 * for Square). The answer goes through ProcessPaymentWebhookJob — run
 * synchronously — so completion has one code path, and the real webhook,
 * when it arrives, finds the row already settled and can't regress it.
 */
final class PendingCheckoutConfirmer
{
    public function __construct(private readonly PaymentGatewayFactory $gateways) {}

    /**
     * Whether the checkout is now Completed.
     */
    public function confirm(PaymentTransaction $transaction): bool
    {
        // getRawOriginal(): Larastan can't infer the enum cast through the
        // method-based casts() (see PaymentGatewayFactory's comment).
        if ($transaction->getRawOriginal('vendor') !== Vendor::Square->value
            || $transaction->getRawOriginal('status') !== PaymentTransactionStatus::Pending->value) {
            return false;
        }

        // The factory aborts when the Event has no e-payment config; a poll
        // should quietly do nothing instead.
        $version = $transaction->version;
        if ($version === null || $version->eventEpaymentConfig() === null) {
            return false;
        }

        $gateway = $this->gateways->make($version);
        if (! $gateway instanceof ConfirmsCheckoutOnReturn) {
            return false;
        }

        $event = $gateway->confirmCheckout($transaction);
        if ($event === null) {
            return false;
        }

        ProcessPaymentWebhookJob::dispatchSync(Vendor::Square, $event);

        return $event->status === PaymentTransactionStatus::Completed;
    }
}
