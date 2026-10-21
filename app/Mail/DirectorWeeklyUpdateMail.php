<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\UnsubscribableEmail;
use App\Models\User;
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
    public string $unsubscribeUrl;

    public function __construct(
        public DirectorWeeklyUpdate $update,
        public User $recipient,
    ) {
        $this->unsubscribeUrl = $recipient->unsubscribeUrl(UnsubscribableEmail::DirectorWeeklyUpdate);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->update->version->name}: Weekly Participation Update",
            using: [function (Email $message): void {
                // Postmark only routes by this header class, not a plain text
                // header of the same name. Other mailers ignore it.
                $message->getHeaders()->add(
                    new MessageStreamHeader((string) config('services.postmark.streams.director_weekly_update')),
                );
                // RFC 8058 one-click unsubscribe, expected by Gmail/Yahoo for scheduled mail.
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$this->unsubscribeUrl}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.director-weekly-update');
    }
}
