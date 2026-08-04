<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared by teacher_payments and candidate_payments (see
 * event-version-orientation.md §5.10/§8.15). Electronic is reserved on both
 * tables this phase — no writer exists yet on either side, blocked on the
 * not-yet-built epayment_credentials integration (§5.2).
 */
enum PaymentType: string
{
    case Check = 'check';
    case PurchaseOrder = 'purchase_order';
    case Cash = 'cash';
    case Other = 'other';
    case Electronic = 'electronic';

    public function label(): string
    {
        return match ($this) {
            self::Check => 'Check',
            self::PurchaseOrder => 'Purchase Order',
            self::Cash => 'Cash',
            self::Other => 'Other',
            self::Electronic => 'Electronic',
        };
    }
}
