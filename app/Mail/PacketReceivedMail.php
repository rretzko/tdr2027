<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirms receipt only — no reconciliation content (see
 * event-version-orientation.md §5.10). Sent as a batch via the
 * Participating Schools report's "Send Confirmations" action, not at each
 * checkbox click.
 */
class PacketReceivedMail extends Mailable
{
    public function __construct(public Version $version) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Packet received for {$this->version->name}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.packet-received');
    }
}
