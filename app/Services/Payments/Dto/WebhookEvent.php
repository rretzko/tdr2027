<?php

declare(strict_types=1);

namespace App\Services\Payments\Dto;

use App\Enums\PaymentTransactionStatus;

final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $vendorTransactionId,
        public PaymentTransactionStatus $status,
        public int $amountCents,
        public array $rawPayload,
    ) {}
}
