<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Version;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Delivers the "All Ensembles" Combined Audition Scores PDF once
 * GenerateCombinedScoresPdfJob finishes rendering it (or immediately, if a
 * still-fresh copy already exists on S3) — this report can't be streamed
 * back synchronously at this app's real data volume, see
 * GenerateCombinedScoresPdfJob's docblock.
 */
class CombinedScoresPdfReadyMail extends Mailable
{
    public function __construct(public Version $version, public bool $confidential, private readonly string $pdfBinary) {}

    public function envelope(): Envelope
    {
        $variant = $this->confidential ? 'Confidential' : 'Public';

        return new Envelope(
            subject: "Combined Audition Scores ({$variant}) ready — {$this->version->name}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.combined-scores-pdf-ready');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $variant = $this->confidential ? 'confidential' : 'public';

        return [
            Attachment::fromData(fn (): string => $this->pdfBinary, "combined-audition-scores-{$variant}-all.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
