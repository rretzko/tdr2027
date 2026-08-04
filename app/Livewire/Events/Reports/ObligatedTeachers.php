<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\ObligationDecision;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\TeacherDisplay;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ObligatedTeachers extends Component
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
        return view('livewire.events.reports.obligated-teachers', [
            'rows' => self::filterAndSort(self::baseRows($this->version, $this->reportCountyIds), $this->search, $this->sortColumn, $this->sortDirection),
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $query = VersionInvitation::where('version_id', $version->id)
            ->whereHas('obligationResponse', fn ($q) => $q->where('decision', ObligationDecision::Accepted->value))
            ->with(['teacher.user.phones', 'teacher.schools', 'teacher.memberships', 'obligationResponse']);

        if ($countyIds !== null) {
            $query->whereHas('teacher.schools', function ($q) use ($countyIds): void {
                $q->whereIn('schools.county_id', $countyIds)
                    ->where('school_teacher.is_active', true)
                    ->whereNotNull('school_teacher.verified_at');
            });
        }

        return $query->get()->map(function (VersionInvitation $invitation) use ($version, $countyIds): array {
            $teacher = $invitation->teacher;

            return [
                'teacher' => $teacher,
                'school' => TeacherDisplay::primarySchool($teacher, $countyIds),
                'phones' => TeacherDisplay::phoneNumbers($teacher),
                'decidedAt' => $invitation->obligationResponse?->decided_at,
                'membershipExpiresAt' => TeacherDisplay::membershipExpiresAt($teacher, $version),
            ];
        });
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

                return str_contains(mb_strtolower($user->first_name), $search)
                    || str_contains(mb_strtolower($user->last_name), $search)
                    || str_contains(mb_strtolower((string) ($row['school']->name ?? '')), $search);
            });
        }

        $sortValue = fn (array $row): string => match ($sortColumn) {
            'school' => mb_strtolower((string) ($row['school']->name ?? '')),
            default => mb_strtolower($row['teacher']->user->last_name),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values();
    }
}
