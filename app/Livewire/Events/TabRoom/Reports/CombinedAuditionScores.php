<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

use App\Enums\PdfExportStatus;
use App\Jobs\GenerateCombinedScoresPdfJob;
use App\Mail\CombinedScoresPdfReadyMail;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\TabRoomReportCache;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base for Tab Room Module.docx's "Combined Audition Scores by voice part"
 * report — every Voice Part belonging to one Ensemble, in one export, or
 * (via the "All" filter option) every Ensemble's Voice Parts together.
 * Concrete per confidential()/public() so each gets its own route/breadcrumb/
 * heading; the row query (TabRoomReportService::combinedScoreRows()/
 * allEnsemblesScoreRows()) and the Blade partial are identical either way —
 * only column projection (identity columns shown or not) differs, driven by
 * the confidential() flag.
 */
#[Layout('components.layouts.app')]
abstract class CombinedAuditionScores extends Component
{
    public Version $version;

    /**
     * '' (nothing chosen), 'all', or an Ensemble id as a string — a plain
     * string rather than a typed nullable int, since the HTML <select>'s
     * "All" option has no natural int representation.
     */
    #[Url]
    public string $ensembleId = '';

    abstract public function confidential(): bool;

    abstract public function exportRouteName(): string;

    abstract public function heading(): string;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $this->version = $version;
    }

    /**
     * The "All Ensembles" PDF can't be streamed back synchronously — DomPDF
     * exceeded a 120s execution limit and a 2GB memory limit rendering it at
     * this app's real data volume (confirmed 2026-08-11). This queues
     * GenerateCombinedScoresPdfJob and emails the result instead, reusing a
     * still-fresh S3 copy (same TabRoomReportCache generation) rather than
     * re-rendering when nothing has changed since the last run.
     */
    public function requestAllEnsemblesPdf(): void
    {
        $generation = TabRoomReportCache::currentGeneration($this->version);
        $confidential = $this->confidential();

        $export = CombinedScoresPdfExport::firstOrNew([
            'version_id' => $this->version->id,
            'confidential' => $confidential,
        ]);

        // Not $export->status === PdfExportStatus::... — Larastan can't
        // infer the enum cast through this method-based casts() return here
        // (cataloged Larastan quirk; see PHPStan-quirks memory), so it flags
        // the comparison as always-false. getRawOriginal() sidesteps that.
        $status = $export->exists ? PdfExportStatus::from((string) $export->getRawOriginal('status')) : null;

        if ($status === PdfExportStatus::Completed && $export->report_generation === $generation) {
            // Storage's 's3' disk sets 'throw' => false, so a missing/
            // unreadable object comes back as null rather than throwing
            // (confirmed in production 2026-08-11: an S3 write had silently
            // failed, leaving a Completed row pointing at an object that
            // was never actually written — get() returning null crashed
            // this Mailable's typed constructor). Falling through to
            // requeue instead of trusting a stale reference is what makes
            // that failure mode self-healing rather than a hard crash.
            $pdfBinary = $export->s3_key !== null ? Storage::disk('s3')->get($export->s3_key) : null;

            if ($pdfBinary !== null) {
                Mail::to(Auth::user()->email)->send(new CombinedScoresPdfReadyMail($this->version, $confidential, $pdfBinary));
                Flux::toast(text: 'Nothing has changed since the last export — re-sending the existing PDF to your email now.', variant: 'success');

                return;
            }
        }

        // 30 minutes comfortably exceeds GenerateCombinedScoresPdfJob's own
        // worst case ($tries=3 × $timeout=600s each). A row stuck at
        // Queued/Processing past that has to be abandoned — a worker
        // crash/restart, or a queue-level failure that never reached the
        // job's own try/catch (exactly what happened in production
        // 2026-08-11: MaxAttemptsExceededException is thrown by the worker
        // itself, before handle() runs, so it never gets a chance to mark
        // the row Failed) — falling through re-dispatches instead of
        // blocking the export forever.
        $isStale = $export->updated_at !== null && $export->updated_at->lt(now()->subMinutes(30));

        if (in_array($status, [PdfExportStatus::Queued, PdfExportStatus::Processing], true) && ! $isStale) {
            Flux::toast(text: "A PDF export is already in progress — you'll be emailed when it's ready.", variant: 'warning');

            return;
        }

        $export->fill([
            'requested_by_user_id' => Auth::id(),
            'status' => PdfExportStatus::Queued,
            'report_generation' => null,
        ])->save();

        GenerateCombinedScoresPdfJob::dispatch($export->id);

        Flux::toast(text: "PDF export queued — you'll receive an email when it's ready.", variant: 'success');
    }

    public function render(TabRoomReportService $reports, EnsembleCutoffService $cutoffs): View
    {
        $ensembles = $this->version->ensembleOrder->map(fn ($order) => $order->ensemble);

        return view('livewire.events.tab-room.reports.combined-audition-scores', [
            'ensembles' => $ensembles,
            'ensembleTables' => $this->ensembleTables($reports, $cutoffs, $ensembles),
            'confidential' => $this->confidential(),
            'exportRouteName' => $this->exportRouteName(),
            'heading' => $this->heading(),
        ]);
    }

    /**
     * A single-item Collection either way — "All Ensembles" has no
     * meaningful per-Ensemble grouping (see allEnsemblesScoreRows()'s
     * docblock), so it's one section labeled with the Version's own name,
     * not one section per Ensemble.
     *
     * @param  Collection<int, Ensemble>  $ensembles
     * @return Collection<int, array{sectionLabel: string, voicePartTables: Collection<int, array{voicePart: VoicePart, columns: Collection<int, array{judge_id: int, score_factor_id: int, score_category_id: int, label: string, category_box: bool, category_shaded: bool, is_category_start: bool, is_category_end: bool, is_judge_start: bool, is_judge_end: bool}>, categoryGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool}>, judgeGroups: Collection<int, array{label: string, span: int, box: bool, shaded: bool, is_category_start: bool, is_category_end: bool}>, rows: Collection<int, array{candidate: Candidate, scores: array<string, int>, total: int, result: string}>}>}>
     */
    private function ensembleTables(TabRoomReportService $reports, EnsembleCutoffService $cutoffs, Collection $ensembles): Collection
    {
        if ($this->ensembleId === 'all') {
            return collect([
                ['sectionLabel' => $this->version->name, 'voicePartTables' => $reports->allEnsemblesScoreRows($this->version, $cutoffs)],
            ]);
        }

        $ensemble = $ensembles->first(fn (Ensemble $candidate): bool => (string) $candidate->id === $this->ensembleId);

        if ($ensemble === null) {
            return collect();
        }

        return collect([
            ['sectionLabel' => $ensemble->name, 'voicePartTables' => $reports->combinedScoreRows($this->version, $ensemble, $cutoffs)],
        ]);
    }
}
