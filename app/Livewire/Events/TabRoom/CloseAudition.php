<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\EventStatus;
use App\Enums\PdfExportStatus;
use App\Jobs\CaptureVersionAnalyticsSnapshotJob;
use App\Jobs\GenerateCombinedScoresPdfJob;
use App\Jobs\GenerateEnsembleParticipantsCsvJob;
use App\Mail\AuditionResultsAvailableMail;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\EnsembleHistoryService;
use App\Services\PlatformInvoiceService;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\TabRoomReportCache;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tab Room Manager's Close Audition sub-module (Tab Room Module.docx):
 * single-button close/reopen, matching the doc's screenshot exactly.
 * Reachable via canManageTabRoom() specifically — not canManageEvent() —
 * since a Tab Room Manager who isn't also an Event Manager has no access
 * to VersionEdit's general Status field at all, and the doc names this as
 * a Tab Room Manager action.
 *
 * `versions.status` is the one place "closed" already lives (also settable
 * via VersionEdit's general Status field, for an Event Manager) —
 * `results_released_at` is a second, narrower flag this page owns
 * exclusively: it's what actually gates teacher-facing results visibility
 * (Registrations\Results/ResultsIndex), so an Event Manager closing a
 * Version for unrelated administrative reasons via the general form never
 * silently releases results as a side effect. Close sets both; Reopen
 * clears results_released_at (and only it) so results hide again during
 * corrections; re-Close re-sets it. Every Close also snapshots Ensemble
 * Cut-offs' current counts into EnsembleHistory (EnsembleHistoryService::
 * recordCurrentSeason()) — the same call VersionEdit's Status field
 * already makes, since this is a second, independent path to Closed.
 */
#[Layout('components.layouts.app')]
class CloseAudition extends Component
{
    public Version $version;

    public bool $emailTeachers = false;

    /**
     * Why the last Close attempt was refused (see undecidedProblems()),
     * shown under the Close button until the next attempt. Empty when the
     * last attempt went through, or none has been made.
     *
     * @var list<string>
     */
    public array $closeProblems = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function close(EnsembleHistoryService $history, EnsembleCutoffService $cutoffs, PlatformInvoiceService $invoices): void
    {
        abort_unless($this->version->getRawOriginal('status') === EventStatus::Active->value, 422);

        $this->closeProblems = $this->undecidedProblems();

        if ($this->closeProblems !== []) {
            Flux::toast(text: "{$this->version->name} wasn't closed — some candidates don't have a result yet.", variant: 'danger');

            return;
        }

        $this->version->update([
            'status' => EventStatus::Closed->value,
            'results_released_at' => now(),
        ]);

        $history->recordCurrentSeason($this->version, $cutoffs);

        // Freeze this Version's end-of-cycle analytics (docs/plans/version-analytics-report.md).
        // A re-Close after a Reopen replaces the snapshot, since reopening is for corrections.
        CaptureVersionAnalyticsSnapshotJob::dispatch($this->version->id, Auth::id(), now()->toIso8601String());

        // One Ensemble Participants CSV per Ensemble for Reports → Downloads,
        // rebuilt on every Close so a corrected re-Close is reflected.
        GenerateEnsembleParticipantsCsvJob::dispatch($this->version->id, Auth::id());

        if ($this->version->share_results) {
            $this->queueSharedScoresPdf();
        }

        if ($this->emailTeachers) {
            foreach ($this->notifiableTeacherEmails() as $email) {
                Mail::to($email)->send(new AuditionResultsAvailableMail($this->version, Auth::user()));
            }
        }

        // TDR's platform-usage invoice to this Version's Event Managers,
        // sent 24h from now by invoices:send-due — a no-op when one was
        // already sent for this Version (send once).
        $invoice = $invoices->scheduleOnClose($this->version, Auth::user());

        $invoiceNote = $invoice->sent_at === null && $invoice->send_at !== null
            ? ' The platform invoice will be emailed to Event Managers in 24 hours.'
            : '';

        Flux::toast(text: "{$this->version->name} closed — results are now available to teachers.{$invoiceNote}", variant: 'success');
    }

    /**
     * Queues the "All Ensembles" public Combined Audition Scores PDF
     * (GenerateCombinedScoresPdfJob) so it's ready on S3 for participating
     * teachers to download directly (SharedScoresPdfController) once
     * share_results is on — the same dedupe-by-generation check
     * CombinedAuditionScores::requestAllEnsemblesPdf() uses for the Tab Room
     * Manager's manual "Email me the PDF" button, minus that method's
     * "already fresh → re-email it" branch, since nobody explicitly
     * requested an email here.
     */
    private function queueSharedScoresPdf(): void
    {
        $generation = TabRoomReportCache::currentGeneration($this->version);

        $export = CombinedScoresPdfExport::firstOrNew(['version_id' => $this->version->id, 'confidential' => false]);

        // getRawOriginal(), not a direct enum comparison — Larastan can't
        // infer the enum cast through this method-based casts() return here
        // (cataloged Larastan quirk; see PHPStan-quirks memory).
        $status = $export->exists ? PdfExportStatus::from((string) $export->getRawOriginal('status')) : null;
        $alreadyFresh = $status === PdfExportStatus::Completed && $export->report_generation === $generation;

        if ($alreadyFresh) {
            return;
        }

        $export->fill([
            'requested_by_user_id' => Auth::id(),
            'status' => PdfExportStatus::Queued,
            'report_generation' => null,
        ])->save();

        GenerateCombinedScoresPdfJob::dispatch($export->id);
    }

    public function reopen(PlatformInvoiceService $invoices): void
    {
        abort_unless($this->version->getRawOriginal('status') === EventStatus::Closed->value, 422);

        $this->version->update([
            'status' => EventStatus::Active->value,
            'results_released_at' => null,
        ]);

        // Reopening inside the 24h window holds the not-yet-sent invoice;
        // the next Close restarts the clock.
        $invoices->holdOnReopen($this->version);

        Flux::toast(text: "{$this->version->name} reopened — teachers will no longer see results until you close again.", variant: 'warning');
    }

    public function render(): View
    {
        $rawStrategy = $this->version->getRawOriginal('cutoff_strategy');

        return view('livewire.events.tab-room.close-audition', [
            'notifiableTeacherCount' => $this->notifiableTeacherEmails()->count(),
            'needsFinalize' => $rawStrategy !== null && CutoffStrategy::from($rawStrategy)->needsPerEnsembleCutoff(),
        ]);
    }

    /**
     * One line per Voice Part that still has Registered (undecided)
     * Candidates — empty when Close may proceed. Closing with any left
     * releases results that silently omit those students (every teacher-
     * facing results view lists only Accepted/NotAccepted/NoShow/
     * Incomplete) and shows them as "err" on the Tab Room reports. The
     * usual cause is a per-Ensemble strategy whose Finalize was never run
     * (v93, 2026-11-22: 87 candidates left Registered). Only enforced once
     * a cut-off strategy is configured: legacy imported Versions were
     * closed with Registered candidates and no strategy, and must stay
     * re-closable.
     *
     * @return list<string>
     */
    private function undecidedProblems(): array
    {
        if ($this->version->getRawOriginal('cutoff_strategy') === null) {
            return [];
        }

        return Candidate::where('version_id', $this->version->id)
            ->where('status', CandidateStatus::Registered)
            ->with('voicePart')
            ->get()
            ->groupBy('voice_part_id')
            ->sortBy(fn (Collection $group): int => (int) ($group->first()?->voicePart->sort_order ?? PHP_INT_MAX))
            ->map(function (Collection $group): string {
                $label = $group->first()?->voicePart->abbr ?? 'No voice part';
                $count = $group->count();

                return "{$label}: {$count} ".str('candidate')->plural($count).' still undecided.';
            })
            ->values()
            ->all();
    }

    /**
     * Distinct teacher emails for Candidates in this Version who have an
     * actual audition outcome (accepted/not_accepted/no_show/incomplete) —
     * not Registered (no decision yet) and not eligible/pending/withdrew
     * (never participated). ParticipatingTeachers::baseRows() (the
     * Registration Manager Reporting Module's own "participating teachers"
     * query) filters to status=Registered specifically, which is empty by
     * the time a Version reaches Close — a different, wider status set is
     * needed here.
     *
     * @return Collection<int, string>
     */
    private function notifiableTeacherEmails(): Collection
    {
        return Candidate::where('version_id', $this->version->id)
            ->whereIn('status', [
                CandidateStatus::Accepted,
                CandidateStatus::NotAccepted,
                CandidateStatus::NoShow,
                CandidateStatus::Incomplete,
            ])
            ->with('teacher.user')
            ->get()
            ->pluck('teacher.user.email')
            ->filter()
            ->unique()
            ->values();
    }
}
