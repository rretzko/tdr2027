<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What PlatformInvoiceService::send() did with one due invoice.
 */
enum PlatformInvoiceSendOutcome: string
{
    case Sent = 'sent';
    case Voided = 'voided';
    case Held = 'held';
    case NoRecipients = 'no_recipients';
    case NoCandidateDate = 'no_candidate_date';
    case Skipped = 'skipped';
}
