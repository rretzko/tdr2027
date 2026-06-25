<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class StudentClaimMail extends Mailable
{
    public string $expiresAt;

    public function __construct(
        public Teacher $requestingTeacher,
        public Student $student,
        public School $school,
        public string $approveUrl,
        public string $denyUrl,
        Carbon $expiresAt,
    ) {
        $this->expiresAt = $expiresAt->format('l, F j, Y g:i A');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->requestingTeacher->user->name} wants to add {$this->student->user->name} as their student",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.student-claim');
    }
}
