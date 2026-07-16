<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Teacher;
use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class VersionInvitationRequestSubmittedMail extends Mailable
{
    public string $expiresAt;

    public function __construct(
        public Teacher $requestingTeacher,
        public Version $version,
        public ?string $schoolName,
        public ?string $countyName,
        public ?string $membershipNumber,
        public ?string $membershipExpiresAt,
        public string $approveUrl,
        public string $denyUrl,
        Carbon $expiresAt,
    ) {
        $this->expiresAt = $expiresAt->format('l, F j, Y g:i A');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->requestingTeacher->user->name} requests an invitation to {$this->version->name}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.version-invitation-request-submitted');
    }
}
