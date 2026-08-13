<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * See epayment-integration.md §1.1/§2.2/§2.3.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }
}
