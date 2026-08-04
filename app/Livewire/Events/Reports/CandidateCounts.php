<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\TeacherDisplay;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CandidateCounts extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $search = '';

    public string $sortColumn = 'school';

    public string $sortDirection = 'asc';

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function render(): View
    {
        $voiceParts = $this->version->availableVoiceParts();
        $candidates = self::scopedCandidates($this->version, $this->reportCountyIds);

        return view('livewire.events.reports.candidate-counts', [
            'voiceParts' => $voiceParts,
            'summary' => self::voicePartSummary($candidates, $voiceParts),
            'rows' => self::filterAndSort(self::rows($candidates, $voiceParts), $this->search, $this->sortColumn, $this->sortDirection),
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
            ->with(['teacher.user.phones', 'school', 'voicePart']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @param  Collection<int, VoicePart>  $voiceParts
     * @return array<int, int>
     */
    public static function voicePartSummary(Collection $candidates, Collection $voiceParts): array
    {
        $counts = $candidates->groupBy('voice_part_id')->map(fn (Collection $g): int => $g->count());

        return $voiceParts->mapWithKeys(fn (VoicePart $vp): array => [$vp->id => $counts->get($vp->id, 0)])->all();
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @param  Collection<int, VoicePart>  $voiceParts
     * @return Collection<int, mixed>
     */
    public static function rows(Collection $candidates, Collection $voiceParts): Collection
    {
        return $candidates->groupBy(fn (Candidate $c): string => "{$c->school_id}:{$c->teacher_id}")
            ->map(function (Collection $group) use ($voiceParts): array {
                $first = $group->first();
                $counts = $group->groupBy('voice_part_id')->map(fn (Collection $g): int => $g->count());

                return [
                    'school' => $first->school,
                    'teacher' => $first->teacher,
                    'phones' => TeacherDisplay::phoneNumbers($first->teacher),
                    'countsByVoicePart' => $voiceParts->mapWithKeys(fn (VoicePart $vp): array => [$vp->id => $counts->get($vp->id, 0)])->all(),
                    'total' => $group->count(),
                ];
            })->values();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    public static function filterAndSort(Collection $rows, string $search, string $sortColumn, string $sortDirection): Collection
    {
        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                $school = $row['school'];

                return str_contains(mb_strtolower($row['teacher']->user->name), $search)
                    || ($school instanceof School && str_contains(mb_strtolower($school->name), $search));
            });
        }

        $sortValue = fn (array $row): string|int => match ($sortColumn) {
            'teacher' => mb_strtolower($row['teacher']->user->name),
            'total' => $row['total'],
            default => mb_strtolower($row['school']->name ?? ''),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values();
    }
}
