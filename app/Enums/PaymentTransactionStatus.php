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

    // Our own timeout classification, never a vendor status — a checkout
    // session (Square Payment Link / PayPal order) nobody ever approved or
    // captured generates no webhook at all, so it would otherwise sit at
    // Pending forever. See ExpireAbandonedPaymentTransactions.
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::Expired => 'Expired',
        };
    }
}
