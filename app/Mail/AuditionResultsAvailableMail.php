<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent as a batch from Close Audition's optional "email participating
 * teachers" checkbox (Tab Room Module.docx's Close Audition sub-module),
 * not at any individual candidate decision — matching PacketReceivedMail's
 * batch-send convention. Signed by the Tab Room Manager who closed the
 * audition (not config('app.name'), unlike this project's other mailables)
 * so the recipient knows who to contact with questions.
 */
class AuditionResultsAvailableMail extends Mailable
{
    public function __construct(public Version $version, public User $closedBy) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Audition results are available for {$this->version->name}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.audition-results-available');
    }
}
