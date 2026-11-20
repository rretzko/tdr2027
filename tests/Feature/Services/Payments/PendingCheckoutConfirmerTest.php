<?php

declare(strict_types=1);

use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Models\EventEpaymentConfig;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Version;
use App\Services\Payments\PendingCheckoutConfirmer;
use App\Services\Payments\SquarePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Square\Types\Money;
use Square\Types\Payment;
use Tests\Support\FakeConfirmingSquareGateway;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
    FakeConfirmingSquareGateway::reset();
});

function confirmerPendingSquareCheckout(?Vendor $vendor = Vendor::Square, bool $configured = true): PaymentTransaction
{
    $version = Version::factory()->create();
    if ($configured) {
        EventEpaymentConfig::create(['event_id' => $version->event_id, 'vendor' => Vendor::Square, 'vendor_account_id' => 'loc-123', 'secret' => 'token-123']);
    }

    return PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::CandidateEpayment,
        'vendor' => $vendor,
        'vendor_transaction_id' => 'order-'.uniqid(),
        'amount' => 2800,
        'status' => PaymentTransactionStatus::Pending,
        'fee_type' => FeeType::Registration,
    ]);
}

test('a checkout Square reports as paid is marked Completed straight away', function () {
    app()->bind(SquarePaymentGateway::class, fn () => new FakeConfirmingSquareGateway);
    $transaction = confirmerPendingSquareCheckout();

    expect(app(PendingCheckoutConfirmer::class)->confirm($transaction))->toBeTrue();

    $transaction->refresh();
    expect($transaction->getRawOriginal('status'))->toBe('completed')
        ->and($transaction->paid_at)->not->toBeNull()
        ->and($transaction->raw_payload)->toBe(['confirmed_on_return' => true]);
});

test('a checkout Square has not settled yet stays Pending for the webhook', function () {
    app()->bind(SquarePaymentGateway::class, fn () => new FakeConfirmingSquareGateway);
    FakeConfirmingSquareGateway::$answer = null;
    $transaction = confirmerPendingSquareCheckout();

    expect(app(PendingCheckoutConfirmer::class)->confirm($transaction))->toBeFalse()
        ->and($transaction->refresh()->getRawOriginal('status'))->toBe('pending');
});

test('a failed Square payment is recorded as Failed, not Completed', function () {
    app()->bind(SquarePaymentGateway::class, fn () => new FakeConfirmingSquareGateway);
    FakeConfirmingSquareGateway::$answer = PaymentTransactionStatus::Failed;
    $transaction = confirmerPendingSquareCheckout();

    expect(app(PendingCheckoutConfirmer::class)->confirm($transaction))->toBeFalse()
        ->and($transaction->refresh()->getRawOriginal('status'))->toBe('failed');
});

test('only pending Square checkouts on a configured Event are asked about', function () {
    app()->bind(SquarePaymentGateway::class, fn () => new FakeConfirmingSquareGateway);
    $confirmer = app(PendingCheckoutConfirmer::class);

    $paypal = confirmerPendingSquareCheckout(Vendor::Paypal);
    $unconfigured = confirmerPendingSquareCheckout(configured: false);
    $completed = confirmerPendingSquareCheckout();
    $completed->update(['status' => PaymentTransactionStatus::Completed]);

    expect($confirmer->confirm($paypal))->toBeFalse()
        ->and($confirmer->confirm($unconfigured))->toBeFalse()
        ->and($confirmer->confirm($completed))->toBeFalse()
        ->and(FakeConfirmingSquareGateway::$calls)->toBe(0);
});

test('a Square payment maps to the same statuses the payment webhook uses', function () {
    $gateway = app(SquarePaymentGateway::class);
    $payment = fn (string $status): Payment => new Payment(['id' => 'pay-1', 'status' => $status, 'amountMoney' => new Money(['amount' => 2800, 'currency' => 'USD'])]);

    $completed = $gateway->eventFromPayment($payment('COMPLETED'), 'order-1');

    expect($completed->status)->toBe(PaymentTransactionStatus::Completed)
        ->and($completed->amountCents)->toBe(2800)
        ->and($completed->vendorTransactionId)->toBe('order-1')
        ->and($completed->rawPayload)->toBe(['confirmed_on_return' => true, 'order_id' => 'order-1', 'payment_id' => 'pay-1', 'status' => 'COMPLETED'])
        ->and($gateway->eventFromPayment($payment('FAILED'), 'order-1')->status)->toBe(PaymentTransactionStatus::Failed)
        ->and($gateway->eventFromPayment($payment('CANCELED'), 'order-1')->status)->toBe(PaymentTransactionStatus::Failed)
        ->and($gateway->eventFromPayment($payment('APPROVED'), 'order-1')->status)->toBe(PaymentTransactionStatus::Pending);
});

test('the real gateway asks Square nothing for a checkout with no order id', function () {
    $transaction = confirmerPendingSquareCheckout();
    $transaction->update(['vendor_transaction_id' => null]);

    expect(app(SquarePaymentGateway::class)->confirmCheckout($transaction->refresh()))->toBeNull();
});
