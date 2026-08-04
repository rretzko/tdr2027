<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\TeacherDisplay;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ParticipatingTeachers extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $search = '';

    public string $sortColumn = 'last_name';

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
        return view('livewire.events.reports.participating-teachers', [
            'rows' => self::filterAndSort(self::baseRows($this->version, $this->reportCountyIds), $this->search, $this->sortColumn, $this->sortDirection),
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $query = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['teacher.user.phones', 'school']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        return $query->get()->groupBy('teacher_id')->map(function (Collection $candidates): array {
            $teacher = $candidates->first()->teacher;

            $school = $candidates->groupBy('school_id')
                ->sortByDesc(fn (Collection $group): int => $group->count())
                ->map(fn (Collection $group) => $group->first()->school)
                ->first();

            return [
                'teacher' => $teacher,
                'school' => $school,
                'phones' => TeacherDisplay::phoneNumbers($teacher),
                'count' => $candidates->count(),
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
                $user = $row['teacher']->user;
                $school = $row['school'];

                return str_contains(mb_strtolower($user->first_name), $search)
                    || str_contains(mb_strtolower($user->last_name), $search)
                    || ($school instanceof School && str_contains(mb_strtolower($school->name), $search));
            });
        }

        $sortValue = fn (array $row): string => match ($sortColumn) {
            'school' => mb_strtolower($row['school']->name),
            default => mb_strtolower($row['teacher']->user->last_name),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values();
    }
}
