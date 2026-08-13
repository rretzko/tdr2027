<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaypalPaymentGateway;
use App\Services\Payments\SquarePaymentGateway;

/**
 * E-payment gateway vendor. See epayment-integration.md §1.2/§2.
 */
enum Vendor: string
{
    case Square = 'square';
    case Paypal = 'paypal';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square',
            self::Paypal => 'PayPal',
        };
    }

    /**
     * @return class-string<PaymentGatewayContract>
     */
    public function gatewayClass(): string
    {
        return match ($this) {
            self::Square => SquarePaymentGateway::class,
            self::Paypal => PaypalPaymentGateway::class,
        };
    }
}
