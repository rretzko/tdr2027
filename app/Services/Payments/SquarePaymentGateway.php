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
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Square\Checkout\PaymentLinks\Requests\CreatePaymentLinkRequest;
use Square\Environments;
use Square\SquareClient;
use Square\Types\CheckoutOptions;
use Square\Types\Money;
use Square\Types\QuickPay;
use Square\Utils\WebhooksHelper;

/**
 * See epayment-integration.md §2.2. Checkout is Square's Payment Links API
 * (hosted, redirect-based); webhook verification is a local HMAC check via
 * the official SDK's WebhooksHelper. The vendor credential (and, for now,
 * the webhook signing key) come from the Version's Event, not the Version
 * itself — see EventEpaymentConfig's own docblock for why.
 */
class SquarePaymentGateway implements PaymentGatewayContract
{
    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher|Student $payer, FeeType $feeType): CheckoutSession
    {
        abort_if($candidates->isEmpty(), 422, 'At least one candidate is required to create a checkout session.');

        $config = $version->eventEpaymentConfig();
        abort_if($config === null, 422, 'This Event is not configured for Square e-payment.');

        // getRawOriginal(), not the magic-cast property — see
        // PaymentGatewayFactory's own comment for why.
        $rawVendor = $config->getRawOriginal('vendor');

        abort_if(
            $rawVendor !== Vendor::Square->value || $config->vendor_account_id === null,
            422,
            'This Event is not configured for Square e-payment.',
        );

        $fees = $version->fees;
        abort_if($fees === null, 422, 'This Version has no fees configured.');

        // Registration and participation are never combined into one
        // checkout amount — see FeeType. The surcharge is an extra line item
        // at electronic checkout only, never part of what's "owed" — so it's
        // added once per transaction here, not per candidate, and the
        // resulting 100%-allocated single-candidate row below will show as a
        // small over-payment by exactly the surcharge amount. That's
        // expected, not a bug.
        $amount = $fees->amountForCheckout($feeType, $candidates->count());

        /** @var Candidate $firstCandidate */
        $firstCandidate = $candidates->first();
        $source = $candidates->count() === 1 ? PaymentSource::CandidateEpayment : PaymentSource::TeacherEpayment;

        // Single-candidate: back to that candidate's own page — the
        // student's own /sfdi/events/{candidate} page for a Student payer
        // (studentfolder-module.md §5.7, always exactly one candidate),
        // otherwise the teacher's registrations.candidate. Group payment
        // (Teacher payer only): no single candidate to return to, so the
        // Version dashboard (§3's "Your Unreconciled Payments" section lives
        // there).
        $redirectUrl = match (true) {
            $payer instanceof Student => route('sfdi.events.candidate', ['candidate' => $firstCandidate->id]),
            $candidates->count() === 1 => route('registrations.candidate', ['version' => $version->id, 'candidate' => $firstCandidate->id]),
            default => route('registrations.version', ['version' => $version->id]),
        };

        $request = new CreatePaymentLinkRequest([
            'idempotencyKey' => (string) Str::uuid(),
            'quickPay' => new QuickPay([
                'name' => "{$version->name} registration",
                'priceMoney' => new Money(['amount' => $amount, 'currency' => 'USD']),
                'locationId' => $config->vendor_account_id,
            ]),
            'checkoutOptions' => new CheckoutOptions(['redirectUrl' => $redirectUrl]),
        ]);

        $response = $this->client($config)->checkout->paymentLinks->create($request);
        $paymentLink = $response->getPaymentLink();

        // §5 item 7: the payment doesn't exist yet at link-creation time, so
        // there's no payment id to correlate on yet — order_id is the only
        // id both this response and the later payment.updated webhook share.
        abort_if(
            $paymentLink === null || $paymentLink->getOrderId() === null || $paymentLink->getUrl() === null,
            502,
            'Square did not return a usable payment link.',
        );

        $transaction = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => $source,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => $paymentLink->getOrderId(),
            'payer_teacher_id' => $payer instanceof Teacher ? $payer->id : null,
            'payer_student_id' => $payer instanceof Student ? $payer->id : null,
            'school_id' => $firstCandidate->school_id,
            'amount' => $amount,
            'status' => PaymentTransactionStatus::Pending,
            'fee_type' => $feeType,
            'paid_at' => null,
        ]);

        // Auto-allocate at creation, not on webhook completion — for a
        // single candidate we already know 100% of this transaction is
        // theirs regardless of when/whether it actually completes. See
        // epayment-integration.md §1.1.
        if ($candidates->count() === 1) {
            PaymentAllocation::create([
                'payment_transaction_id' => $transaction->id,
                'candidate_id' => $firstCandidate->id,
                'amount' => $amount,
                'allocated_at' => now(),
            ]);
        }

        return new CheckoutSession(redirectUrl: $paymentLink->getUrl(), paymentTransactionId: $transaction->id);
    }

    public function verifyWebhookSignature(Request $request, Event $event): bool
    {
        $config = $event->activeEpaymentConfig();
        $signatureKey = $config?->webhook_signature_key;
        abort_if($signatureKey === null, 500, "No Square webhook signature key configured for Event #{$event->id}.");

        $signatureHeader = $request->header('x-square-hmacsha256-signature');
        abort_if(! is_string($signatureHeader), 400, 'Missing Square webhook signature header.');

        // The registered per-Event route URL, not $request->fullUrl() — must
        // exactly match the notification URL configured in Square's console
        // for THIS business's webhook subscription (§5 item 8), which a
        // proxy/load-balancer-seen URL isn't guaranteed to match.
        return WebhooksHelper::verifySignature(
            requestBody: $request->getContent(),
            signatureHeader: $signatureHeader,
            signatureKey: $signatureKey,
            notificationUrl: route('webhooks.payments.square', ['event' => $event->id]),
        );
    }

    public function parseWebhookEvent(Request $request): WebhookEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $paymentData = data_get($payload, 'data.object.payment');
        $refundData = data_get($payload, 'data.object.refund');

        if (is_array($paymentData)) {
            return new WebhookEvent(
                vendorTransactionId: (string) ($paymentData['order_id'] ?? ''),
                status: match ($paymentData['status'] ?? null) {
                    'COMPLETED' => PaymentTransactionStatus::Completed,
                    'FAILED', 'CANCELED' => PaymentTransactionStatus::Failed,
                    default => PaymentTransactionStatus::Pending,
                },
                amountCents: (int) data_get($paymentData, 'amount_money.amount', 0),
                rawPayload: $payload,
            );
        }

        if (is_array($refundData)) {
            return new WebhookEvent(
                vendorTransactionId: (string) ($refundData['order_id'] ?? ''),
                status: ($refundData['status'] ?? null) === 'COMPLETED'
                    ? PaymentTransactionStatus::Refunded
                    : PaymentTransactionStatus::Pending,
                amountCents: (int) data_get($refundData, 'amount_money.amount', 0),
                rawPayload: $payload,
            );
        }

        abort(422, 'Unrecognized Square webhook event type: '.($payload['type'] ?? 'unknown'));
    }

    /**
     * The Event's own credential, not an app-wide token — confirmed
     * necessary 2026-08-13 when a real screenshot showed Event 1 (CJMEA) and
     * Event 9 (NJMEA) are two entirely separate Square businesses despite
     * sharing an Organization. This method originally read a single global
     * env token; fixed to use EventEpaymentConfig::$secret instead (§1.2).
     */
    private function client(EventEpaymentConfig $config): SquareClient
    {
        $token = $config->secret;
        abort_if($token === null, 422, 'No Square access token configured for this Event.');

        $environment = config('services.payments.environment', 'sandbox');

        return new SquareClient(
            token: $token,
            options: [
                'baseUrl' => $environment === 'production'
                    ? Environments::Production->value
                    : Environments::Sandbox->value,
            ],
        );
    }
}
