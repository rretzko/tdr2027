<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Models\Candidate;
use App\Models\Version;
use App\Services\VoicePartAuditService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Founder audit of each Candidate's voice part against their teacher-approved
 * recordings (docs/plans/voice-part-recording-audit.md). All data and the
 * write live in VoicePartAuditService; authorization is isolated in
 * authorizeAudit() so the page can later move to Event Manager / Registration
 * Manager permissions by swapping that method and the route.
 */
#[Layout('components.layouts.app')]
class VoicePartAudit extends Component
{
    use HasCandidateChecklist;
    use WithPagination;

    private const int PER_PAGE = 25;

    #[Url(as: 'version')]
    public ?int $versionId = null;

    #[Url]
    public string $status = VoicePartAuditService::STATUS_ACTIVE;

    #[Url(as: 'voice_part')]
    public ?int $voicePartId = null;

    #[Url(as: 'mismatch')]
    public bool $mismatchOnly = false;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = 'voice_part';

    #[Url]
    public string $direction = 'asc';

    /** Bumped on a refused change so the row's select re-renders to the stored value. */
    public int $refreshNonce = 0;

    public function mount(): void
    {
        $this->authorizeAudit();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['versionId', 'status', 'voicePartId', 'mismatchOnly', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['voice_part', 'student', 'teacher'], true)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function changeVoicePart(int $candidateId, string $voicePartId, VoicePartAuditService $audit): void
    {
        $this->authorizeAudit();

        $candidate = Candidate::query()
            ->whereKey($candidateId)
            ->where('version_id', $this->versionId)
            ->with('student.user')
            ->first();

        if ($candidate === null || $voicePartId === '') {
            $this->refreshNonce++;
            Flux::toast(variant: 'danger', text: 'That candidate is not in the selected version.');

            return;
        }

        $name = "{$candidate->student->user->last_name}, {$candidate->student->user->first_name}";

        try {
            ['from' => $from, 'to' => $to] = $audit->changeVoicePart($candidate, (int) $voicePartId, $this->checklistDefs($candidate->version));
        } catch (VoicePartChangeRefusedException $e) {
            $this->refreshNonce++;
            Flux::toast(variant: 'danger', heading: $name, text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: "{$name}: ".($from->name ?? 'None')." → {$to->name}");
    }

    /**
     * Founder-only today. To move this page to Event Managers / Registration
     * Managers, swap this for VersionRoleAssignmentService::canManageReports()
     * (plus ScopesReports county scoping) on a version-bound route.
     */
    private function authorizeAudit(): void
    {
        abort_unless(Auth::user()?->isFounder() === true, 403);
    }

    public function render(VoicePartAuditService $audit): View
    {
        $version = $this->versionId !== null
            ? Version::query()->with('event')->find($this->versionId)
            : null;

        $rows = null;

        if ($version !== null) {
            $all = $audit->rows($version, [
                'status' => $this->status,
                'voicePartId' => $this->voicePartId,
                'mismatchOnly' => $this->mismatchOnly,
                'search' => $this->search,
                'sort' => $this->sort,
                'direction' => $this->direction,
            ]);

            $page = $this->getPage();
            $rows = new LengthAwarePaginator(
                $all->forPage($page, self::PER_PAGE)->values(),
                $all->count(),
                self::PER_PAGE,
                $page,
            );
        }

        return view('livewire.founder.voice-part-audit', [
            'versions' => $this->versionOptions(),
            'version' => $version,
            'rows' => $rows,
            'voiceParts' => $version !== null ? $version->availableVoiceParts() : new EloquentCollection,
            'statuses' => CandidateStatus::cases(),
        ]);
    }

    /**
     * Versions with at least one Candidate, newest first.
     *
     * @return EloquentCollection<int, Version>
     */
    private function versionOptions(): EloquentCollection
    {
        return Version::query()
            ->whereHas('candidates')
            ->with('event')
            ->orderByDesc('senior_class_of')
            ->orderByDesc('id')
            ->get();
    }
}
