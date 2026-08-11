<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a queued, S3-cached PDF export (see
 * App\Jobs\GenerateCombinedScoresPdfJob and
 * App\Models\CombinedScoresPdfExport) — a background job is needed at all
 * because DomPDF cannot render the "All Ensembles" Combined Audition Scores
 * PDF within a normal request's time/memory limits at this app's real data
 * volume (confirmed 2026-08-11: ~1,420 rows exceeded both a 120s execution
 * limit and a 2GB memory limit).
 */
enum PdfExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
