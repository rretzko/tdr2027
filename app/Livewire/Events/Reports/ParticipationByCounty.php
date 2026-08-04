<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Enums\ObligationDecision;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ParticipationByCounty extends Component
{
    use ScopesReports;

    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function render(VersionRoleAssignmentService $roles): View
    {
        return view('livewire.events.reports.participation-by-county', [
            'rows' => self::baseRows($this->version, $this->reportCountyIds, $roles),
        ]);
    }

    /**
     * County universe: the Version's own configured counties
     * (version_counties). If the Version has none configured (unrestricted),
     * derive the set from live data instead of listing every county in the
     * system — every county referenced by a registered Candidate's school or
     * by a Co-Registration Manager assignment on this Version. Either way,
     * further intersected with the acting user's reportCountyIds scope.
     *
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds, VersionRoleAssignmentService $roles): Collection
    {
        $versionCountyIds = $version->counties()->pluck('county_id')->all();

        if ($versionCountyIds === []) {
            $fromCandidates = Candidate::where('version_id', $version->id)
                ->where('status', CandidateStatus::Registered->value)
                ->whereHas('school')
                ->with('school')
                ->get()
                ->pluck('school.county_id')
                ->filter()
                ->all();

            $fromManagers = CoRegistrationManagerCounty::where('version_id', $version->id)->pluck('county_id')->all();

            $baseCountyIds = array_values(array_unique(array_merge($fromCandidates, $fromManagers)));
        } else {
            $baseCountyIds = $versionCountyIds;
        }

        if ($countyIds !== null) {
            $baseCountyIds = array_values(array_intersect($baseCountyIds, $countyIds));
        }

        $counties = County::whereIn('id', $baseCountyIds)->orderBy('name')->get();

        $managersByCounty = CoRegistrationManagerCounty::where('version_id', $version->id)
            ->with('user')
            ->get()
            ->keyBy('county_id');

        $registrationManagerNames = $roles->assignmentsForVersion($version)
            ->get('Registration Manager', collect())
            ->pluck('name')
            ->implode(', ');

        return $counties->map(function (County $county) use ($version, $managersByCounty, $registrationManagerNames): array {
            $manager = $managersByCounty->get($county->id);

            return [
                'county' => $county,
                'obligatedTeacherCount' => VersionInvitation::where('version_id', $version->id)
                    ->whereHas('obligationResponse', fn ($q) => $q->where('decision', ObligationDecision::Accepted->value))
                    ->whereHas('teacher.schools', function ($q) use ($county): void {
                        $q->where('schools.county_id', $county->id)
                            ->where('school_teacher.is_active', true)
                            ->whereNotNull('school_teacher.verified_at');
                    })
                    ->count(),
                'participatingTeacherCount' => Candidate::where('version_id', $version->id)
                    ->where('status', CandidateStatus::Registered->value)
                    ->whereHas('school', fn ($q) => $q->where('county_id', $county->id))
                    ->distinct('teacher_id')
                    ->count('teacher_id'),
                'candidateCount' => Candidate::where('version_id', $version->id)
                    ->where('status', CandidateStatus::Registered->value)
                    ->whereHas('school', fn ($q) => $q->where('county_id', $county->id))
                    ->count(),
                'managerName' => $manager?->user->name ?? ($registrationManagerNames !== '' ? $registrationManagerNames : null),
            ];
        });
    }
}
