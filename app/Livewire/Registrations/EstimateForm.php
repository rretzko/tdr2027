<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\CoTeacherAccessService;
use App\Services\MailToAddressResolver;
use App\Support\EstimateFormData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Real implementation — event-version-orientation.md §5.13. Route deliberately
 * kept {version}-only (not {version}/{school}) so it stays trackable via
 * App\Livewire\Founder\TrackablePages::isTrackable(); the per-school PDF
 * download carries {school} on its own controller route instead (see
 * EstimateFormPdfController).
 */
#[Layout('components.layouts.app')]
class EstimateForm extends Component
{
    use GuardsAcceptedObligations;

    public Version $version;

    public function mount(Version $version, CoTeacherAccessService $coTeacherAccess): void
    {
        $teacher = $this->teacher();

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($invitation === null) {
            // Reachable from VersionDashboard's quick-links row, which a
            // granted co-teacher with no invitation of their own can now
            // open too (docs/plans/co-teacher-definition.md §3).
            $hasGrantedCandidates = $coTeacherAccess->candidateQuery($teacher)
                ->where('version_id', $version->id)
                ->exists();

            abort_unless($hasGrantedCandidates, 403);

            $this->version = $version;

            return;
        }

        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
    }

    public function render(MailToAddressResolver $mailToResolver, CoTeacherAccessService $coTeacherAccess): View
    {
        $teacher = $this->teacher();
        $schools = $this->schoolsWithCandidates($teacher, $coTeacherAccess);

        // Independent of $schools->count() — a membership requirement is
        // Version+teacher-scoped, not per-school (see
        // EstimateFormData::membershipCardStatus()'s own docblock), so it's
        // shown once here rather than only in the single-school branch.
        $membershipStatus = EstimateFormData::membershipCardStatus($this->version, $teacher);

        return view('livewire.registrations.estimate-form', [
            'schools' => $schools,
            'registeredCounts' => $this->registeredCountsBySchool($teacher, $schools, $coTeacherAccess),
            'membershipCardRequired' => $membershipStatus['required'],
            'membershipCardImageUrl' => $membershipStatus['imageUrl'],
            // Only built for the single-school case — the multi-school picker
            // shows counts only, per §5.13 ("no inline on-screen summary per
            // school in the multi-school case").
            'singleSchoolData' => $schools->count() === 1
                ? EstimateFormData::build($this->version, $schools->first(), $teacher, $mailToResolver)
                : null,
        ]);
    }

    /**
     * The teacher's active+verified schools that have at least one
     * registered Candidate on this Version — one Estimate Form per school,
     * per §5.13's multi-school resolution.
     *
     * @return Collection<int, School>
     */
    private function schoolsWithCandidates(Teacher $teacher, CoTeacherAccessService $coTeacherAccess): Collection
    {
        // A co-teacher can only ever hold a grant at a school where they're
        // already active+verified themselves (grantableTeachers() enforces
        // this at grant time), so restricting to the teacher's own active
        // schools here never excludes a legitimately granted one.
        $activeSchoolIds = $teacher->schools()
            ->wherePivot('is_active', true)
            ->wherePivot('verified_at', '!=', null)
            ->pluck('schools.id');

        // Includes any candidate visible via an active co-teaching grant, not
        // just this teacher's own (docs/plans/co-teacher-definition.md §3).
        $schoolIdsWithCandidates = $coTeacherAccess->candidateQuery($teacher)
            ->where('version_id', $this->version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->whereIn('school_id', $activeSchoolIds)
            ->distinct()
            ->pluck('school_id');

        return School::whereIn('id', $schoolIdsWithCandidates)->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, School>  $schools
     * @return Collection<int, int> school_id => registered candidate count
     */
    private function registeredCountsBySchool(Teacher $teacher, Collection $schools, CoTeacherAccessService $coTeacherAccess): Collection
    {
        return $coTeacherAccess->candidateQuery($teacher)
            ->where('version_id', $this->version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->whereIn('school_id', $schools->pluck('id'))
            ->selectRaw('school_id, count(*) as candidate_count')
            ->groupBy('school_id')
            ->pluck('candidate_count', 'school_id');
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
