<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\EventEpaymentConfig;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\AmountWithBreakdown;
use PaypalServerSdkLib\Models\OrderApplicationContext;
use PaypalServerSdkLib\Models\OrderRequest;
use PaypalServerSdkLib\Models\PurchaseUnitRequest;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

/**
 * See epayment-integration.md §2.3. Checkout is PayPal's Orders API v2
 * (`intent=CAPTURE`): create an order, redirect the payer to its `approve`
 * link, then capture on return — unlike Square's Payment Links, PayPal's
 * Orders API doesn't auto-capture on approval, so this gateway also owns
 * captureOrder() (called by PaypalReturnController), which has no Square
 * equivalent and isn't part of PaymentGatewayContract for that reason.
 * Webhook verification is a server-to-server call to PayPal's own
 * verify-webhook-signature endpoint, not a local check — the official SDK
 * has no helper for it, so it's a direct HTTP call here.
 */
class PaypalPaymentGateway implements PaymentGatewayContract
{
    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher $payer, FeeType $feeType): CheckoutSession
    {
        abort_if($candidates->isEmpty(), 422, 'At least one candidate is required to create a checkout session.');

        $config = $version->eventEpaymentConfig();
        abort_if($config === null, 422, 'This Event is not configured for PayPal e-payment.');

        // getRawOriginal(), not the magic-cast property — see
        // PaymentGatewayFactory's own comment for why.
        $rawVendor = $config->getRawOriginal('vendor');

        abort_if($rawVendor !== Vendor::Paypal->value, 422, 'This Event is not configured for PayPal e-payment.');

        $fees = $version->fees;
        abort_if($fees === null, 422, 'This Version has no fees configured.');

        // Registration and participation are never combined into one
        // checkout amount — see FeeType. The surcharge is an extra line item
        // at electronic checkout only — added once per transaction, not per
        // candidate. See SquarePaymentGateway's identical comment for the
        // full reasoning.
        $amount = $fees->amountForCheckout($feeType, $candidates->count());

        /** @var Candidate $firstCandidate */
        $firstCandidate = $candidates->first();
        $source = $candidates->count() === 1 ? PaymentSource::CandidateEpayment : PaymentSource::TeacherEpayment;

        // Unlike Square's redirect_url (the final destination page itself),
        // this points at our own capture-handling route — PayPal's approval
        // step doesn't move any money, capturing on return does (see this
        // class's own docblock). $next is validated Version/Candidate ids,
        // reconstructed into a route server-side by PaypalReturnController —
        // never a raw trusted URL, to avoid an open-redirect vector.
        $returnUrl = $candidates->count() === 1
            ? route('payments.paypal.return', ['version' => $version->id, 'candidate' => $firstCandidate->id])
            : route('payments.paypal.return', ['version' => $version->id]);

        $orderRequest = new OrderRequest('CAPTURE', [
            new PurchaseUnitRequest(new AmountWithBreakdown('USD', number_format($amount / 100, 2, '.', ''))),
        ]);

        $applicationContext = new OrderApplicationContext;
        $applicationContext->setReturnUrl($returnUrl);
        $applicationContext->setCancelUrl($returnUrl);
        $orderRequest->setApplicationContext($applicationContext);

        $response = $this->client($config)->getOrdersController()->createOrder([
            'body' => $orderRequest,
            'paypalRequestId' => (string) Str::uuid(),
            'prefer' => 'return=representation',
        ]);

        $order = $response->getResult();

        $approveLink = collect($order->getLinks() ?? [])->first(fn ($link): bool => $link->getRel() === 'approve');

        abort_if(
            $order->getId() === null || $approveLink === null,
            502,
            'PayPal did not return a usable order/approval link.',
        );

        $transaction = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => $source,
            'vendor' => Vendor::Paypal,
            // Unlike Square (§5 item 7), PayPal's order id is known
            // immediately at creation and is the same id every later
            // webhook event correlates back to — no order_id/payment_id
            // split to work around here.
            'vendor_transaction_id' => $order->getId(),
            'payer_teacher_id' => $payer->id,
            'school_id' => $firstCandidate->school_id,
            'amount' => $amount,
            'status' => PaymentTransactionStatus::Pending,
            'fee_type' => $feeType,
            'paid_at' => null,
        ]);

        if ($candidates->count() === 1) {
            PaymentAllocation::create([
                'payment_transaction_id' => $transaction->id,
                'candidate_id' => $firstCandidate->id,
                'amount' => $amount,
                'allocated_at' => now(),
            ]);
        }

        return new CheckoutSession(redirectUrl: $approveLink->getHref(), paymentTransactionId: $transaction->id);
    }

    /**
     * Called by PaypalReturnController once the payer approves and PayPal
     * redirects back — approval alone doesn't move money for an
     * intent=CAPTURE order. Updates $transaction in place; the same
     * out-of-order-webhook guard ProcessPaymentWebhookJob uses doesn't apply
     * here since this always runs before any capture-related webhook can
     * plausibly arrive, but a webhook arriving first is still safe — this
     * only ever moves status toward Completed/Failed, never backward.
     */
    public function captureOrder(PaymentTransaction $transaction): void
    {
        $version = Version::findOrFail($transaction->version_id);
        $config = $version->eventEpaymentConfig();
        abort_if($config === null, 422, 'This Event is not configured for PayPal e-payment.');

        $response = $this->client($config)->getOrdersController()->captureOrder([
            'id' => $transaction->vendor_transaction_id,
            'prefer' => 'return=representation',
        ]);

        $order = $response->getResult();
        $status = $order->getStatus();

        $transaction->update([
            'status' => $status === 'COMPLETED' ? PaymentTransactionStatus::Completed : PaymentTransactionStatus::Failed,
            'paid_at' => $status === 'COMPLETED' ? ($transaction->paid_at ?? now()) : $transaction->paid_at,
        ]);
    }

    public function verifyWebhookSignature(Request $request, Event $event): bool
    {
        $config = $event->activeEpaymentConfig();
        $webhookId = $config?->webhook_signature_key;
        abort_if($webhookId === null, 500, "No PayPal webhook id configured for Event #{$event->id}.");

        $token = $this->accessToken($config);

        $response = Http::withToken($token)->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function parseWebhookEvent(Request $request): WebhookEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $eventType = $payload['event_type'] ?? null;
        $resource = $payload['resource'] ?? [];

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            return new WebhookEvent(
                vendorTransactionId: (string) data_get($resource, 'supplementary_data.related_ids.order_id', ''),
                status: PaymentTransactionStatus::Completed,
                amountCents: $this->centsFromAmount(data_get($resource, 'amount', [])),
                rawPayload: $payload,
            );
        }

        if ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            return new WebhookEvent(
                vendorTransactionId: (string) data_get($resource, 'supplementary_data.related_ids.order_id', ''),
                status: PaymentTransactionStatus::Failed,
                amountCents: $this->centsFromAmount(data_get($resource, 'amount', [])),
                rawPayload: $payload,
            );
        }

        if ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            return new WebhookEvent(
                vendorTransactionId: (string) data_get($resource, 'supplementary_data.related_ids.order_id', ''),
                status: PaymentTransactionStatus::Refunded,
                amountCents: $this->centsFromAmount(data_get($resource, 'amount', [])),
                rawPayload: $payload,
            );
        }

        // Approval alone doesn't move money (see captureOrder()'s docblock)
        // — this app's own return-flow always captures explicitly, so this
        // event is a no-op signal in practice (Pending -> Pending), kept
        // only so an unrecognized-event abort() doesn't fire on a real,
        // expected PayPal event.
        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            return new WebhookEvent(
                vendorTransactionId: (string) ($resource['id'] ?? ''),
                status: PaymentTransactionStatus::Pending,
                amountCents: 0,
                rawPayload: $payload,
            );
        }

        abort(422, 'Unrecognized PayPal webhook event type: '.($eventType ?? 'unknown'));
    }

    /**
     * @param  array<string, mixed>  $amount
     */
    private function centsFromAmount(array $amount): int
    {
        return (int) round(((float) ($amount['value'] ?? 0)) * 100);
    }

    /**
     * The Event's own credential (Client ID in vendor_account_id, Client
     * Secret in secret) — same per-Event requirement as Square, see
     * EventEpaymentConfig's own docblock.
     */
    private function client(EventEpaymentConfig $config): PaypalServerSdkClient
    {
        abort_if($config->vendor_account_id === null || $config->secret === null, 422, 'No PayPal Client ID/Secret configured for this Event.');

        $environment = config('services.payments.environment', 'sandbox');

        return PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init($config->vendor_account_id, $config->secret),
            )
            ->environment($environment === 'production' ? Environment::PRODUCTION : Environment::SANDBOX)
            ->build();
    }

    private function accessToken(EventEpaymentConfig $config): string
    {
        abort_if($config->vendor_account_id === null || $config->secret === null, 422, 'No PayPal Client ID/Secret configured for this Event.');

        $response = Http::asForm()
            ->withBasicAuth($config->vendor_account_id, $config->secret)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        abort_if(! $response->successful(), 502, 'Failed to authenticate with PayPal.');

        return (string) $response->json('access_token');
    }

    private function baseUrl(): string
    {
        return config('services.payments.environment', 'sandbox') === 'production'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
