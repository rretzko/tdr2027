<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Models\Candidate;
use App\Models\Version;
use App\Services\VoicePartAuditService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Shared UI for the Voice Part ↔ Recording audit
 * (docs/plans/voice-part-recording-audit.md). All data and the write live in
 * VoicePartAuditService; subclasses decide who may use it, which Version it
 * covers, and the county scope:
 *
 * - Founder\VoicePartAudit — full page, any Version via a picker.
 * - Events\VersionVoicePartAudit — embedded tab on Web Registration, bound to
 *   one Version, Event Manager / Web Registration Manager / Registration
 *   Manager access with Co-Registration Manager county scoping.
 */
abstract class AbstractVoicePartAudit extends Component
{
    use HasCandidateChecklist;
    use WithPagination;

    private const int PER_PAGE = 25;

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

    /** Aborts (403) unless the acting user may use this audit for $versionId. */
    abstract protected function authorizeAudit(): void;

    /**
     * Counties the acting user is limited to; null = every county.
     *
     * @return list<int>|null
     */
    abstract protected function countyIds(Version $version): ?array;

    /** Whether to show the page heading and Version picker (false when embedded). */
    abstract protected function standalone(): bool;

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
            ->with(['student.user', 'school', 'version'])
            ->first();

        $countyIds = $candidate !== null ? $this->countyIds($candidate->version) : null;
        $outOfScope = $candidate !== null && $countyIds !== null
            && ! in_array((int) ($candidate->school->county_id ?? 0), array_map(intval(...), $countyIds), true);

        if ($candidate === null || $outOfScope || $voicePartId === '') {
            $this->refreshNonce++;
            Flux::toast(variant: 'danger', text: 'That candidate is not available to you in this version.');

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
                'countyIds' => $this->countyIds($version),
            ]);

            $page = $this->getPage();
            $rows = new LengthAwarePaginator(
                $all->forPage($page, self::PER_PAGE)->values(),
                $all->count(),
                self::PER_PAGE,
                $page,
            );
        }

        return view('livewire.voice-part-audit.index', [
            'standalone' => $this->standalone(),
            'versions' => $this->standalone() ? $this->versionOptions() : new EloquentCollection,
            'version' => $version,
            'rows' => $rows,
            'voiceParts' => $version !== null ? $version->availableVoiceParts() : new EloquentCollection,
            'statuses' => CandidateStatus::cases(),
        ]);
    }

    /**
     * Versions with at least one Candidate, newest first (Version picker).
     *
     * @return EloquentCollection<int, Version>
     */
    protected function versionOptions(): EloquentCollection
    {
        return Version::query()
            ->whereHas('candidates')
            ->with('event')
            ->orderByDesc('senior_class_of')
            ->orderByDesc('id')
            ->get();
    }
}
