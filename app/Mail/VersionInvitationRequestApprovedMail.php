<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VersionInvitationRequestApprovedMail extends Mailable
{
    public function __construct(public Version $version) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your invitation to {$this->version->name} was approved",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.version-invitation-request-approved');
    }
}
