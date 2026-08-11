<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PdfExportStatus;
use App\Mail\CombinedScoresPdfReadyMail;
use App\Models\CombinedScoresPdfExport;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Support\Reports\TabRoomReportCache;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Renders the "All Ensembles" Combined Audition Scores PDF and emails it to
 * whoever requested it — run as a queued job, not inline in the request,
 * because DomPDF cannot render this report within a normal request's
 * time/memory limits at this app's real data volume. Measured against a
 * live Version with ~1,420 rows across ~12 Voice Part tables (2026-08-11):
 * ~137s and a ~1.45GB peak — comfortably placed inside this job's own
 * $timeout and memory_limit bump below, but well past a normal web
 * request's 30s/512MB ceiling. A single Ensemble's PDF (~170 rows) still
 * renders in ~9.6s within 512MB and stays synchronous (see
 * CombinedAuditionScoresExportController) — only the unfiltered "All
 * Ensembles" case needs this.
 *
 * The finished PDF is stored on S3 (disk 'combinedConfidentialPdfs'/
 * 'combinedPublicPdfs' key prefix per variant) and tagged with the
 * TabRoomReportCache generation it was built from, so a later request at an
 * unchanged generation can reuse it instead of re-running this job — see
 * CombinedAuditionScores::requestAllEnsemblesPdf().
 *
 * $timeout is well above the render time this job actually needs (confirmed
 * production incident 2026-08-11: the database queue connection's default
 * `retry_after` of 90s is shorter than this PDF takes to render — the
 * job's reservation expired *while still legitimately rendering*, so a
 * second worker poll saw it as available again, and the resulting double
 * attempt exceeded the command's default `tries=1` via
 * MaxAttemptsExceededException — thrown by the worker itself, before
 * handle() ever runs again, so this job's own try/catch never saw it and
 * the export row was left stuck at Processing forever). $timeout must stay
 * comfortably under config/queue.php's 'database' connection `retry_after`
 * (bumped to 900s alongside this fix) — see that file's comment. In
 * production (Vapor/SQS), the equivalent is the SQS queue's own visibility
 * timeout, configured outside this repo (vapor.yml/AWS console) — it must
 * also exceed this value; this class can't enforce that from here.
 */
class GenerateCombinedScoresPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(private readonly int $exportId) {}

    public function handle(TabRoomReportService $reports, EnsembleCutoffService $cutoffs): void
    {
        $export = CombinedScoresPdfExport::findOrFail($this->exportId);

        // A genuine PHP memory-exhaustion fatal ("Allowed memory size ...
        // exhausted") is NOT a catchable Throwable — it bypasses the
        // try/catch below entirely and just kills the process. Confirmed in
        // production (2026-08-11, before the memory_limit bump above
        // existed): the export row was left stuck at Processing forever,
        // with no Failed status and no failed_jobs row, since nothing ever
        // ran to record it — the 30-minute staleness fallback in
        // CombinedAuditionScores::requestAllEnsemblesPdf() was the only way
        // out. register_shutdown_function() is the standard way to still
        // observe this class of fatal: PHP reserves a little memory
        // headroom past memory_limit specifically so shutdown callbacks can
        // still run after one. Not unit-tested — deliberately triggering an
        // *uncatchable* fatal inside a test process to assert against it
        // isn't practical; this is a defense-in-depth net for a failure
        // mode already fixed at its source (the memory_limit bump), not the
        // primary fix.
        register_shutdown_function(function () use ($export): void {
            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $export->refresh();

            if ($export->getRawOriginal('status') === PdfExportStatus::Processing->value) {
                $export->update(['status' => PdfExportStatus::Failed, 'failure_reason' => $error['message']]);
            }
        });

        try {
            // PHP's default CLI memory_limit (512MB) crashed this render in
            // production (2026-08-11: "Allowed memory size ... exhausted" in
            // dompdf/src/Css/Style.php) — confirmed peak usage is ~1.45GB
            // for real "All Ensembles" data. Safe to raise here specifically
            // (rather than in php.ini globally) because this runs isolated
            // in a background worker, not a request other traffic shares.
            ini_set('memory_limit', '3072M');

            $export->update(['status' => PdfExportStatus::Processing]);

            $version = $export->version;
            $generation = TabRoomReportCache::currentGeneration($version);

            $voicePartTables = $reports->allEnsemblesScoreRows($version, $cutoffs);

            $pdfBinary = Pdf::loadView('pdf.reports.tab-room.combined-audition-scores', [
                'version' => $version,
                'ensembleTables' => collect([['sectionLabel' => $version->name, 'voicePartTables' => $voicePartTables]]),
                'confidential' => $export->confidential,
            ])->setPaper('a4', 'landscape')->output();

            $prefix = $export->confidential ? 'combinedConfidentialPdfs' : 'combinedPublicPdfs';
            $key = "{$prefix}/{$version->id}/{$generation}.pdf";

            // Not fire-and-forget — config/filesystems.php's 's3' disk sets
            // 'throw' => false, so a failed put() (confirmed in production
            // 2026-08-11: a 403 from AWS on this bucket) returns false
            // silently instead of throwing. Trusting that silently would
            // mark this export Completed with an s3_key pointing at an
            // object that was never actually written — exactly what
            // happened: the direct-render email that same run worked fine
            // (it mails $pdfBinary straight from memory, never touching
            // S3), masking the failure until a later "reuse" request tried
            // to Storage::get() the phantom key and got null back.
            if (Storage::disk('s3')->put($key, $pdfBinary) === false) {
                throw new RuntimeException("Failed to store the generated PDF on S3 at key: {$key}");
            }

            $export->update([
                's3_key' => $key,
                'report_generation' => $generation,
                'status' => PdfExportStatus::Completed,
                'failure_reason' => null,
            ]);

            Mail::to($export->requestedBy->email)->send(new CombinedScoresPdfReadyMail($version, $export->confidential, $pdfBinary));
        } catch (Throwable $e) {
            $export->update(['status' => PdfExportStatus::Failed, 'failure_reason' => $e->getMessage()]);

            throw $e;
        }
    }
}
