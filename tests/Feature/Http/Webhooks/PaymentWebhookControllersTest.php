<?php

declare(strict_types=1);

use App\Enums\PaymentEnvironment;
use App\Enums\Vendor;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\EventEpaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.payments.environment' => PaymentEnvironment::Sandbox->value]);
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{0: string, 1: string}
 */
function signedSquareWebhook(EventEpaymentConfig $config, array $payload): array
{
    $body = (string) json_encode($payload);
    $url = route('webhooks.payments.square', ['event' => $config->event_id]);
    $signature = base64_encode(hash_hmac('sha256', $url.$body, (string) $config->webhook_signature_key, true));

    return [$body, $signature];
}

function postSquareWebhook(EventEpaymentConfig $config, string $body, string $signature): TestResponse
{
    return call(
        'POST',
        route('webhooks.payments.square', ['event' => $config->event_id]),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature],
        $body,
    );
}

test('square acknowledges an unhandled event type with 204 and dispatches nothing', function (): void {
    $config = EventEpaymentConfig::factory()->create(['environment' => PaymentEnvironment::Sandbox]);
    [$body, $signature] = signedSquareWebhook($config, ['type' => 'customer.created', 'data' => ['object' => ['customer' => ['id' => 'C1']]]]);

    postSquareWebhook($config, $body, $signature)->assertNoContent();

    Queue::assertNothingPushed();
});

test('square dispatches the job for a payment event', function (): void {
    $config = EventEpaymentConfig::factory()->create(['environment' => PaymentEnvironment::Sandbox]);
    [$body, $signature] = signedSquareWebhook($config, [
        'type' => 'payment.updated',
        'data' => ['object' => ['payment' => ['order_id' => 'ORDER1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 2000]]]],
    ]);

    postSquareWebhook($config, $body, $signature)->assertNoContent();

    Queue::assertPushed(ProcessPaymentWebhookJob::class);
});

test('square still rejects an invalid signature with 400', function (): void {
    $config = EventEpaymentConfig::factory()->create(['environment' => PaymentEnvironment::Sandbox]);
    [$body] = signedSquareWebhook($config, ['type' => 'customer.created']);

    postSquareWebhook($config, $body, 'not-a-valid-signature')->assertStatus(400);

    Queue::assertNothingPushed();
});

test('paypal acknowledges an unhandled event type with 204 and dispatches nothing', function (): void {
    $config = EventEpaymentConfig::factory()->create(['environment' => PaymentEnvironment::Sandbox, 'vendor' => Vendor::Paypal]);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    postJson(route('webhooks.payments.paypal', ['event' => $config->event_id]), ['event_type' => 'BILLING.PLAN.CREATED', 'resource' => []])
        ->assertNoContent();

    Queue::assertNothingPushed();
});
