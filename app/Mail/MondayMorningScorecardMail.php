<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Version;
use App\Support\VersionScorecardMetrics;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\MessageStreamHeader;
use Symfony\Component\Mime\Email;

/**
 * Sent every Monday morning by SendMondayMorningScorecardEmails to a Version's
 * Event Managers while the Version is Active, summarizing invitation,
 * obligation, registration, and registration-fee progress.
 */
class MondayMorningScorecardMail extends Mailable
{
    public function __construct(
        public Version $version,
        public VersionScorecardMetrics $metrics,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Monday Morning Scorecard',
            // See DirectorWeeklyUpdateMail: Postmark only routes by this header class.
            using: [fn (Email $message) => $message->getHeaders()->add(
                new MessageStreamHeader((string) config('services.postmark.streams.monday_scorecard')),
            )],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monday-morning-scorecard');
    }
}
