<?php

declare(strict_types=1);

namespace App\Services\Payments\Dto;

final readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        public int $paymentTransactionId,
    ) {}
}
