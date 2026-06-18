<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Pivots\SchoolTeacher;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SchoolEmailVerificationMail extends Mailable
{
    public function __construct(
        public SchoolTeacher $schoolTeacher,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your school email for '.$this->schoolTeacher->school->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.school-email-verification');
    }
}
