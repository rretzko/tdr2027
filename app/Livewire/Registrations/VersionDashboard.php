<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Services\CandidateService;
use App\Services\EligibilityService;
use App\Services\VersionInvitationEligibilityService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionDashboard extends Component
{
    use HasCandidateChecklist;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $voicePartFilter = '';

    #[Url]
    public string $statusFilter = '';

    /**
     * Page-level counterpart to EligibilityService::isNotInvited() — without
     * this, any teacher with an active school could open this page directly
     * (nav visibility alone is not an authorization boundary) and enroll
     * candidates into a Version they were never invited to.
     */
    public function mount(Version $version, VersionInvitationEligibilityService $eligibility): void
    {
        $teacher = $this->teacher();

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($invitation === null) {
            if ($eligibility->isEligible($version, $teacher)) {
                $this->redirect(route('registrations.request-invitation', $version), navigate: true);

                return;
            }

            abort(403);
        }

        // A teacher who has never responded to a published obligation is sent
        // there first. A teacher who already rejected it still lands here —
        // that's handled by the "Participation stopped" banner below, not a
        // redirect, since they've already made a decision (just the wrong one).
        $obligation = $version->obligation;

        if ($obligation?->isPublished() && $invitation->obligationResponse()->doesntExist()) {
            $this->redirect(route('registrations.obligations', $version), navigate: true);

            return;
        }

        $this->version = $version;
    }

    public function withdraw(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $name = $candidate->program_name;
        $candidates->withdraw($candidate);

        Flux::toast("{$name} has been withdrawn.");
    }

    public function refreshStatus(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts'])
            ->firstOrFail();

        $checklistDefs = $this->checklistDefs($this->version);
        $candidates->recalculateStatus($candidate, $checklistDefs);

        Flux::toast("{$candidate->program_name} status updated.");
    }

    public function render(): View
    {
        $teacher = $this->teacher();

        // Sorted by the student's sort_name (Last, First — the same "alpha
        // order" convention used elsewhere, e.g. VersionInvitationEligibilityService::roster()),
        // not program_name — a teacher can freely edit program_name to
        // anything for the concert program, so it isn't a reliable
        // alphabetical key.
        $myCandidates = Candidate::where('version_id', $this->version->id)
            ->where('teacher_id', $teacher->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts', 'voicePart'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name));

        $filteredCandidates = $this->filterCandidates($myCandidates);

        // Summary tables reflect the full roster (myCandidates), not the
        // filtered view — a stable overview regardless of the search/filter
        // row below it.
        // Registered-only: this table answers "how many per voice part have
        // actually made it to Registered," not a raw headcount across every
        // status — the "Registered" column is the sum of that, not a
        // cross-status grand total.
        $voicePartCounts = $this->version->availableVoiceParts()
            ->map(fn (VoicePart $voicePart): array => [
                'label' => $voicePart->abbr,
                'count' => $myCandidates->where('voice_part_id', $voicePart->id)->where('status', CandidateStatus::Registered)->count(),
            ]);
        $voicePartTotal = $voicePartCounts->sum('count');

        $statusCounts = collect(CandidateStatus::registrationStates())
            ->map(fn (CandidateStatus $status): array => [
                'label' => $status->label(),
                'count' => $myCandidates->where('status', $status)->count(),
            ]);
        $statusTotal = $statusCounts->sum('count');

        $statusOptions = $myCandidates
            ->pluck('status')
            ->unique(fn (CandidateStatus $status): string => $status->value)
            ->sortBy(fn (CandidateStatus $status): string => $status->label())
            ->values();

        $upcomingDates = $this->version->dates()
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->limit(6)
            ->get();

        $voiceParts = VoicePart::ordered()->get();

        $checklistDefs = $this->checklistDefs($this->version);

        $obligationsRejected = app(EligibilityService::class)->isBlockedByRejectedObligations($this->version, $teacher);

        return view('livewire.registrations.version-dashboard', compact(
            'myCandidates', 'filteredCandidates', 'voicePartCounts', 'voicePartTotal',
            'statusCounts', 'statusTotal', 'statusOptions',
            'upcomingDates', 'voiceParts', 'checklistDefs', 'obligationsRejected',
        ));
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, Candidate>
     */
    private function filterCandidates(Collection $candidates): Collection
    {
        $search = mb_strtolower(trim($this->search));

        if ($search !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => str_contains(mb_strtolower((string) $candidate->student->user->name), $search));
        }

        if ($this->voicePartFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => (string) $candidate->voice_part_id === $this->voicePartFilter);
        }

        if ($this->statusFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => $candidate->getRawOriginal('status') === $this->statusFilter);
        }

        return $candidates->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
