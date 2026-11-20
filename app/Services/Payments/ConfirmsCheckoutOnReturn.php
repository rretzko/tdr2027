<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentTransaction;
use App\Services\Payments\Dto\WebhookEvent;

/**
 * A gateway that can ask its vendor directly whether a pending checkout has
 * been paid, so the payer's return to TDR doesn't have to wait for the
 * webhook. Square only: PayPal already captures synchronously on return
 * (PaypalReturnController), so it never has the gap.
 */
interface ConfirmsCheckoutOnReturn
{
    /**
     * The vendor's current answer for this checkout, shaped like the
     * webhook it stands in for — or null when it isn't settled yet, or the
     * vendor couldn't be asked (the webhook remains the fallback).
     */
    public function confirmCheckout(PaymentTransaction $transaction): ?WebhookEvent;
}
