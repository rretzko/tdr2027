<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\DirectorWeeklyUpdate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent every Tuesday morning by SendDirectorWeeklyUpdateEmails to each
 * invited/obligated/participating director of an Active Version, summarizing
 * their own students' registration progress, fees, and key dates.
 */
class DirectorWeeklyUpdateMail extends Mailable
{
    public function __construct(
        public DirectorWeeklyUpdate $update,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->update->version->name}: Weekly Participation Update",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.director-weekly-update');
    }
}
