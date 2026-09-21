<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent by NotifyExpiringTeacherAccessWindows to a Version's Event Managers
 * when its teacher access window is about to (or already did) start while
 * the Version is still sitting in a non-Active status — teachers can't reach
 * the event pages until someone flips it to Active on the Configure screen.
 */
class TeacherAccessWindowExpiringMail extends Mailable
{
    public function __construct(
        public Version $version,
        public string $configureUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Action needed: {$this->version->name} teacher access goes live soon",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.teacher-access-window-expiring');
    }
}
