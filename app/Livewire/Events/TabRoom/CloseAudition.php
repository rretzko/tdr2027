<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Mail\AuditionResultsAvailableMail;
use App\Models\Candidate;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\EnsembleHistoryService;
use App\Services\VersionRoleAssignmentService;
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

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function close(EnsembleHistoryService $history, EnsembleCutoffService $cutoffs): void
    {
        abort_unless($this->version->getRawOriginal('status') === EventStatus::Active->value, 422);

        $this->version->update([
            'status' => EventStatus::Closed->value,
            'results_released_at' => now(),
        ]);

        $history->recordCurrentSeason($this->version, $cutoffs);

        if ($this->emailTeachers) {
            foreach ($this->notifiableTeacherEmails() as $email) {
                Mail::to($email)->send(new AuditionResultsAvailableMail($this->version, Auth::user()));
            }
        }

        Flux::toast(text: "{$this->version->name} closed — results are now available to teachers.", variant: 'success');
    }

    public function reopen(): void
    {
        abort_unless($this->version->getRawOriginal('status') === EventStatus::Closed->value, 422);

        $this->version->update([
            'status' => EventStatus::Active->value,
            'results_released_at' => null,
        ]);

        Flux::toast(text: "{$this->version->name} reopened — teachers will no longer see results until you close again.", variant: 'warning');
    }

    public function render(): View
    {
        return view('livewire.events.tab-room.close-audition', [
            'notifiableTeacherCount' => $this->notifiableTeacherEmails()->count(),
        ]);
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
