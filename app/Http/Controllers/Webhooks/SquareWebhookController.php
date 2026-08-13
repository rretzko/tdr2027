<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Vendor;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\Event;
use App\Services\Payments\SquarePaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * See epayment-integration.md §2.2/§2.4. Per-Event route ({event} identifies
 * which business's webhook subscription this claims to be from — see §5
 * item 8) — verifies the signature, responds 200 immediately, and defers
 * the actual payment_transactions update to a queued job so a slow/failed
 * update never causes Square to retry delivery.
 */
class SquareWebhookController extends Controller
{
    public function __invoke(Request $request, Event $event, SquarePaymentGateway $gateway): Response
    {
        abort_unless($gateway->verifyWebhookSignature($request, $event), 400, 'Invalid Square webhook signature.');

        $webhookEvent = $gateway->parseWebhookEvent($request);

        ProcessPaymentWebhookJob::dispatch(Vendor::Square, $webhookEvent);

        return response()->noContent();
    }
}
