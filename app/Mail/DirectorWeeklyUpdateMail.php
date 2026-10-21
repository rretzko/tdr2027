<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\DirectorWeeklyUpdate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\MessageStreamHeader;
use Symfony\Component\Mime\Email;

/**
 * Sent every Wednesday morning by SendDirectorWeeklyUpdateEmails to each
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
            // Postmark only routes by this header class, not a plain text
            // header of the same name. Other mailers ignore it.
            using: [fn (Email $message) => $message->getHeaders()->add(
                new MessageStreamHeader((string) config('services.postmark.streams.director_weekly_update')),
            )],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.director-weekly-update');
    }
}
