<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Placeholder shell (resolved via clarifying question, event-version-
 * orientation.md §5.10) — real filters and a real "Print Registration
 * Cards" link exist, but the generated PDF is placeholder content until
 * room<->Candidate assignment is designed (§7 territory).
 */
#[Layout('components.layouts.app')]
class RegistrationCards extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $candidateIdFilter = '';

    #[Url]
    public string $schoolFilter = '';

    #[Url]
    public string $voicePartFilter = '';

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function render(): View
    {
        $candidates = self::scopedCandidates($this->version, $this->reportCountyIds);

        return view('livewire.events.reports.registration-cards', [
            'schoolOptions' => $candidates->pluck('school.name')->filter()->unique()->sort()->values(),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, Candidate>
     */
    public static function scopedCandidates(Version $version, ?array $countyIds): Collection
    {
        $query = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['student.user', 'school', 'voicePart']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, Candidate>
     */
    public static function applyFilters(Collection $candidates, string $candidateIdFilter, string $schoolFilter, string $voicePartFilter): Collection
    {
        if ($candidateIdFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $c): bool => (string) $c->id === $candidateIdFilter || $c->ref === $candidateIdFilter);
        }

        if ($schoolFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $c): bool => ($c->school->name ?? null) === $schoolFilter);
        }

        if ($voicePartFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $c): bool => (string) $c->voice_part_id === $voicePartFilter);
        }

        return $candidates->values();
    }
}
