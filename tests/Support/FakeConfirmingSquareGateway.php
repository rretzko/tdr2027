<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\PaymentTransaction;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\Payments\ConfirmsCheckoutOnReturn;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use App\Services\Payments\PaymentGatewayContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Stands in for SquarePaymentGateway's return confirmation: answers
 * confirmCheckout() with whatever status the test sets, without calling
 * Square. Bind it with app()->bind(SquarePaymentGateway::class, ...).
 */
final class FakeConfirmingSquareGateway implements ConfirmsCheckoutOnReturn, PaymentGatewayContract
{
    /** Null means "not settled yet" — confirmCheckout() returns null. */
    public static ?PaymentTransactionStatus $answer = PaymentTransactionStatus::Completed;

    public static int $calls = 0;

    public function confirmCheckout(PaymentTransaction $transaction): ?WebhookEvent
    {
        self::$calls++;

        if (self::$answer === null) {
            return null;
        }

        return new WebhookEvent(
            vendorTransactionId: (string) $transaction->vendor_transaction_id,
            status: self::$answer,
            amountCents: $transaction->amount,
            rawPayload: ['confirmed_on_return' => true],
        );
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher|Student $payer, FeeType $feeType): CheckoutSession
    {
        throw new RuntimeException('Not used by return-confirmation tests.');
    }

    public function verifyWebhookSignature(Request $request, Event $event): bool
    {
        return true;
    }

    public function parseWebhookEvent(Request $request): ?WebhookEvent
    {
        return null;
    }

    public static function reset(): void
    {
        self::$answer = PaymentTransactionStatus::Completed;
        self::$calls = 0;
    }
}
