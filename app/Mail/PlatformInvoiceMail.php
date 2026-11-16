<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Billing\PlatformInvoiceData;
use App\Support\Billing\PlatformInvoicePdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * TDR's platform-usage invoice (MFR Holdings, LLC), PDF attached.
 * Transactional Postmark stream (the default — no broadcast header).
 *
 * Variants: 'invoice' (first send to the Version's Event Managers),
 * 'resend' (Founder re-mail of the stored snapshot), 'test' (Founder
 * preview, founder only), 'no_recipients' / 'no_candidate_date'
 * (founder-only alerts when the invoice can't go out: no Event Manager, or
 * a virtual Version with no Candidate date to start the storage window).
 */
class PlatformInvoiceMail extends Mailable
{
    public const VARIANT_INVOICE = 'invoice';

    public const VARIANT_RESEND = 'resend';

    public const VARIANT_TEST = 'test';

    public const VARIANT_NO_RECIPIENTS = 'no_recipients';

    public const VARIANT_NO_CANDIDATE_DATE = 'no_candidate_date';

    public function __construct(public PlatformInvoiceData $data, public string $variant = self::VARIANT_INVOICE) {}

    public function envelope(): Envelope
    {
        $base = "Invoice {$this->data->number} — {$this->data->versionName} platform usage";

        return new Envelope(
            subject: match ($this->variant) {
                self::VARIANT_TEST => "[TEST] {$base}",
                self::VARIANT_RESEND => "{$base} (resent)",
                self::VARIANT_NO_RECIPIENTS => "[NO RECIPIENTS] Invoice for {$this->data->versionName} could not be sent",
                self::VARIANT_NO_CANDIDATE_DATE => "[NEEDS ATTENTION] Invoice for {$this->data->versionName} could not be sent: no Candidate date",
                default => $base,
            },
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform-invoice');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => PlatformInvoicePdf::render($this->data), PlatformInvoicePdf::filename($this->data))
                ->withMime('application/pdf'),
        ];
    }
}
