<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a VersionPlatformInvoice (docs/plans/close-audition-invoice.md):
 * Close Audition creates it Pending (send_at = +24h); Reopen before then
 * moves it to Held; invoices:send-due moves Pending → Sent (or Void when
 * there are zero registrants); the Founder marks Sent ↔ Paid.
 */
enum PlatformInvoiceStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Sent = 'sent';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Scheduled',
            self::Held => 'On Hold',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Held => 'amber',
            self::Sent => 'indigo',
            self::Paid => 'green',
            self::Void => 'zinc',
        };
    }

    /**
     * Statuses that already carry a sent snapshot (number, amount, due date).
     *
     * @return list<self>
     */
    public static function issuedStates(): array
    {
        return [self::Sent, self::Paid];
    }
}
