<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Candidate;
use App\Models\Event;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A single vendor-agnostic e-payment gateway interface — see
 * epayment-integration.md §2.1. Implementations: SquarePaymentGateway
 * (built first, real sandbox verified), PaypalPaymentGateway (built second).
 */
interface PaymentGatewayContract
{
    /**
     * Creates the payment_transactions row (status=pending) and the
     * vendor-hosted checkout session in one step, so the row exists before
     * the payer is redirected — see epayment-integration.md §2.1 for why.
     *
     * @param  Collection<int, Candidate>  $candidates  One candidate for a
     *                                                  candidate_epayment; more than one for a teacher_epayment
     *                                                  lump-sum group payment (§0/§1.1) — the transaction amount is
     *                                                  always the sum across every candidate here.
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher $payer): CheckoutSession;

    /**
     * $event identifies which business's webhook subscription this request
     * claims to be from — its EventEpaymentConfig holds the key to verify
     * against. Each vendor gets its own per-Event webhook route (§2.4) so
     * this is known before the payload itself can be trusted at all.
     */
    public function verifyWebhookSignature(Request $request, Event $event): bool;

    public function parseWebhookEvent(Request $request): WebhookEvent;
}
