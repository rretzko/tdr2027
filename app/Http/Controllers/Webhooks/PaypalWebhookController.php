<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Vendor;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\Event;
use App\Services\Payments\PaypalPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * See epayment-integration.md §2.3/§2.4. Per-Event route, same reasoning as
 * SquareWebhookController — verifies the signature (a server-to-server call
 * to PayPal, not a local check), responds 200 immediately, and defers the
 * actual payment_transactions update to a queued job.
 */
class PaypalWebhookController extends Controller
{
    public function __invoke(Request $request, Event $event, PaypalPaymentGateway $gateway): Response
    {
        abort_unless($gateway->verifyWebhookSignature($request, $event), 400, 'Invalid PayPal webhook signature.');

        $webhookEvent = $gateway->parseWebhookEvent($request);

        ProcessPaymentWebhookJob::dispatch(Vendor::Paypal, $webhookEvent);

        return response()->noContent();
    }
}
